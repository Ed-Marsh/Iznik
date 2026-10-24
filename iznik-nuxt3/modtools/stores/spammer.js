import { defineStore } from 'pinia'
import { runHoldAware, asHeldByOtherError } from '~/api/heldConflict'
import api from '~/api'
import { fetchMe } from '~/composables/useMe'
import { useUserStore } from '~/stores/user'
import { useMemberStore } from '~/stores/member'

// How many of a bulk action's requests run at once. Each member is still its own
// request to the API, which has no bulk endpoint; this keeps fifty of them from
// arriving in one burst without making the moderator wait for them one by one.
const BULK_CONCURRENCY = 5

// Run fn over items, at most BULK_CONCURRENCY at a time. Returns which items went
// through and which did not, with the error for each failure, so the caller can
// tell the moderator exactly what still needs doing.
async function runInBatches(items, fn) {
  const done = []
  const failed = []

  for (let i = 0; i < items.length; i += BULK_CONCURRENCY) {
    const batch = items.slice(i, i + BULK_CONCURRENCY)
    const results = await Promise.allSettled(batch.map((item) => fn(item)))

    results.forEach((result, j) => {
      if (result.status === 'fulfilled') {
        done.push(batch[j])
      } else {
        failed.push({ item: batch[j], error: result.reason })
      }
    })
  }

  return { done, failed }
}

export const useSpammerStore = defineStore('spammer', {
  state: () => ({
    // Use array because we need to store them in the order returned by the server.
    list: [],

    // The context from the last fetch, used for fetchMore.
    context: null,

    // For spotting when we clear under the feet of an outstanding fetch
    instance: 1,
  }),
  actions: {
    init(config) {
      this.config = config
    },
    clear() {
      this.list = []
      this.context = null

      if (this.instance) {
        this.instance++
      } else {
        this.instance = 1
      }
    },

    async fetch(params) {
      // Watch out for the store being cleared under the feet of this fetch. If that happens then we throw away the
      // results.
      const instance = this.instance

      delete params.context
      if (this.context) {
        // V2 API expects context as a simple ID value
        params.context = this.context.id
      }

      const { spammers, context } = await api(this.config).spammers.fetch(
        params
      )
      if (this.instance === instance) {
        // Batch-fetch user data for all userids and byuserids in the response.
        await this.fetchUsers(spammers)

        this.addAll(spammers)
        this.context = context
      }
    },

    async fetchUsers(spammers) {
      const userStore = useUserStore()
      const ids = new Set()

      spammers.forEach((s) => {
        if (s.userid) ids.add(s.userid)
        if (s.byuserid) ids.add(s.byuserid)
      })

      if (ids.size > 0) {
        await userStore.fetchMultiple([...ids], true)
      }
    },

    addAll(items) {
      const userStore = useUserStore()
      const memberStore = useMemberStore()

      items.forEach((item) => {
        // Build a user object from the user store, decorated with spammer info.
        const userData = userStore.list[item.userid]

        if (userData) {
          item.user = {
            ...userData,
            userid: userData.id,
            heldby: item.heldby || null,
            spammer: {
              id: item.id,
              collection: item.collection,
              reason: item.reason,
              added: item.added,
              byuserid: item.byuserid,
              heldby: item.heldby || null,
              heldat: item.heldat || null,
            },
          }

          // Enrich byuser from the user store.
          if (item.byuserid) {
            const byUserData = userStore.list[item.byuserid]
            if (byUserData) {
              item.byuser = {
                id: byUserData.id,
                displayname: byUserData.displayname,
                email: byUserData.email,
              }
              item.user.spammer.byuser = item.byuser
            }
          }
        } else {
          // Fallback: minimal user object so components don't crash.
          item.user = {
            id: item.userid,
            userid: item.userid,
            heldby: item.heldby || null,
            spammer: {
              id: item.id,
              collection: item.collection,
              reason: item.reason,
              added: item.added,
              byuserid: item.byuserid,
              heldby: item.heldby || null,
              heldat: item.heldat || null,
            },
          }
        }

        // Populate the member store so ModMember can find this entry.
        // The spammers page passes spammer.user.id as membershipid to ModMember.
        memberStore.list[item.user.id] = item.user

        const existing = this.list.findIndex((obj) => {
          return parseInt(obj.id) === parseInt(item.id)
        })

        if (existing !== -1) {
          this.list[existing] = item
        } else {
          this.list.push(item)
        }
      })
    },
    removeFromList(itemid) {
      this.list = this.list.filter((obj) => {
        return parseInt(itemid) !== parseInt(obj.id)
      })
    },

    async report(params) {
      await api(this.config).spammers.add({
        userid: params.userid,
        collection: 'PendingAdd',
        reason: params.reason,
      })

      await fetchMe(true)
    },
    async confirm(params) {
      if (!params.id) {
        console.error(
          'spammer confirm: missing spam_users.id — spammer data not loaded yet?'
        )
        return
      }
      await runHoldAware(
        () =>
          api(this.config).spammers.patch({
            id: params.id,
            userid: params.userid,
            collection: 'Spammer',
          }),
        () => this.fetch({ collection: 'PendingAdd' })
      )

      await fetchMe(true)

      this.removeFromList(params.id)
    },

    // Confirm many Pending Add reports at once. items: [{ id, userid }], where id
    // is the spam_users row. A row held by another moderator is refused by the
    // server with a 409 and comes back in `failed` naming the holder; the rest go
    // through. Rows that went through leave the list.
    async confirmMany(items) {
      const withIds = items.filter((item) => item.id)

      const result = await runInBatches(withIds, async (item) => {
        try {
          await api(this.config).spammers.patch({
            id: item.id,
            userid: item.userid,
            collection: 'Spammer',
          })
        } catch (e) {
          throw asHeldByOtherError(e) || e
        }
      })

      result.done.forEach((item) => this.removeFromList(item.id))

      if (result.failed.some((f) => f.error?.heldByOtherMod)) {
        // Someone took a hold after we fetched; bring that onto the screen.
        this.context = null
        await this.fetch({ collection: 'PendingAdd' })
      }

      await fetchMe(true)

      return result
    },

    // Add many members to the spammer list in one go, all with the same reason.
    // collection is 'Spammer' for a Spam admin adding directly, or 'PendingAdd'
    // for a moderator reporting them for the spam team to confirm.
    async addMany({ userids, reason, collection }) {
      const result = await runInBatches(userids, (userid) =>
        api(this.config).spammers.add({
          userid,
          collection,
          reason,
        })
      )

      await fetchMe(true)

      return result
    },

    async requestremove(params) {
      await api(this.config).spammers.add({
        id: params.id,
        userid: params.userid,
        collection: 'PendingRemove',
      })

      this.removeFromList(params.id)

      await fetchMe(true)
    },
    async remove(params) {
      await api(this.config).spammers.del({
        id: params.id,
        userid: params.userid,
      })

      await fetchMe(true)

      this.removeFromList(params.id)
    },
    async safelist(params) {
      await api(this.config).spammers.add({
        id: params.id,
        userid: params.userid,
        reason: params.reason,
        collection: 'Whitelisted',
      })

      await fetchMe(true)

      this.removeFromList(params.id)
    },

    async hold(params) {
      if (!params.id) {
        console.error(
          'spammer hold: missing spam_users.id — spammer data not loaded yet?'
        )
        return
      }
      await runHoldAware(
        () =>
          api(this.config).spammers.patch({
            id: params.id,
            userid: params.userid,
            reason: params.reason,
            collection: 'PendingAdd',
            heldby: params.myid,
          }),
        () => this.fetch({ collection: 'PendingAdd' })
      )

      this.context = null

      this.fetch({
        collection: 'PendingAdd',
      })
    },

    async release(params) {
      if (!params.id) {
        console.error(
          'spammer release: missing spam_users.id — spammer data not loaded yet?'
        )
        return
      }
      // Omitting heldby results in NULL on server.
      await api(this.config).spammers.patch({
        id: params.id,
        userid: params.userid,
        reason: params.reason,
        collection: 'PendingAdd',
      })

      this.context = null

      this.fetch({
        collection: 'PendingAdd',
      })
    },
  },
  getters: {
    byId: (state) => (id) => {
      return state.list.find((s) => parseInt(s.id) === parseInt(id)) || null
    },

    getList: (state) => (collection) => {
      return state.list.filter((s) => s.collection === collection)
    },

    getContext: (state) => () => {
      let ret = null

      if (state.context && state.context.id) {
        ret = state.context
      }

      return ret
    },
  },
})
