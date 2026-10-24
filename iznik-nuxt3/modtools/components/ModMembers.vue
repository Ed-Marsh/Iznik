<template>
  <div>
    <ModBulkSelectBar
      v-if="visibleMembers.length"
      :count="bulk.count.value"
      :limit="bulk.limit"
      :shown-ids="shownUserIds"
      :copy-rows="copyRows"
      :busy="bulkBusy"
      @select-all="bulk.selectAll"
      @clear="bulk.clear"
    >
      <b-button
        variant="danger"
        size="sm"
        :disabled="bulkBusy || !bulk.count.value"
        data-test="bulk-spam"
        @click="showBulkSpam = true"
      >
        <v-icon v-if="bulkBusy" icon="sync" class="fa-spin" />
        <v-icon v-else icon="ban" />
        <span v-if="hasPermissionSpamAdmin">
          Add {{ bulk.count.value }} to spammer list
        </span>
        <span v-else> Report {{ bulk.count.value }} as spammers </span>
      </b-button>
    </ModBulkSelectBar>
    <NoticeMessage
      v-if="bulkResult"
      :variant="bulkResult.failed.length ? 'warning' : 'success'"
      class="mb-2"
      data-test="bulk-result"
    >
      <div v-if="bulkResult.done">
        <span v-if="bulkResult.direct">
          Added {{ bulkResult.done }} to the spammer list.
        </span>
        <span v-else>
          Reported {{ bulkResult.done }} to the Spam team for checking.
        </span>
      </div>
      <div v-if="bulkResult.failed.length">
        {{ bulkResult.failed.length }} could not be
        {{ bulkResult.direct ? 'added' : 'reported' }}:
        <ul class="mb-0">
          <li v-for="f in bulkResult.failed" :key="'bulkfail-' + f.userid">
            #{{ f.userid }} {{ f.name }}: {{ f.message }}
          </li>
        </ul>
      </div>
    </NoticeMessage>
    <div
      v-for="member in visibleMembers"
      :key="'memberlist-' + member.id"
      class="p-0 mt-2 d-flex align-items-start"
    >
      <b-form-checkbox
        :model-value="bulk.isSelected(member.userid)"
        :disabled="bulkBusy"
        title="Select"
        class="mt-2 me-1 bulk-check"
        :data-test="'bulk-check-' + member.userid"
        @update:model-value="bulk.toggle(member.userid)"
      />
      <ModMember
        :membershipid="member.id"
        :actions="false"
        :expand-comments="parseInt(filter) === 1"
        class="flex-grow-1 min-width-0"
      />
    </div>
    <ModBulkSpammerReport
      v-if="showBulkSpam"
      :count="bulk.count.value"
      :direct="hasPermissionSpamAdmin"
      @confirm="bulkSpam"
      @hidden="showBulkSpam = false"
    />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { setupModMembers } from '~/composables/useModMembers'
import { useBulkSelect } from '~/modtools/composables/useBulkSelect'
import { getPreferredEmail } from '~/modtools/composables/usePreferredEmail'
import { useModMe } from '~/composables/useModMe'
import { useSpammerStore } from '~/stores/spammer'
import { useUserStore } from '~/stores/user'

const { filter, visibleMembers } = setupModMembers()
const { hasPermissionSpamAdmin } = useModMe()
const spammerStore = useSpammerStore()
const userStore = useUserStore()

// Bulk selection keyed by userid, not membership: a search across all communities
// lists one row per membership, and picking a person once must pick them once.
const bulk = useBulkSelect()
const bulkBusy = ref(false)
const bulkResult = ref(null)
const showBulkSpam = ref(false)

const shownUserIds = computed(() => [
  ...new Set(visibleMembers.value.map((m) => m.userid)),
])

function rowFor(userid) {
  const member = visibleMembers.value.find((m) => m.userid === userid)
  const user = userStore.list?.[userid]
  const joined = member?.added || member?.joined

  return {
    id: userid,
    name: user?.displayname || member?.displayname || member?.fullname || '',
    email: member?.email || getPreferredEmail(user) || '',
    added: joined ? String(joined).slice(0, 10) : '',
  }
}

// The selected people, or everyone shown when nothing is selected, as plain
// fields for the spam team's records.
const copyRows = computed(() => {
  const ids = bulk.count.value ? bulk.selected.value : shownUserIds.value
  return ids.map(rowFor)
})

async function bulkSpam(reason) {
  const userids = [...bulk.selected.value]
  if (!userids.length) return

  const direct = Boolean(hasPermissionSpamAdmin.value)
  bulkBusy.value = true
  bulkResult.value = null

  try {
    const result = await spammerStore.addMany({
      userids,
      reason,
      collection: direct ? 'Spammer' : 'PendingAdd',
    })

    bulkResult.value = {
      direct,
      done: result.done.length,
      failed: result.failed.map((f) => ({
        userid: f.item,
        name: rowFor(f.item).name,
        message: f.error?.message || 'Something went wrong',
      })),
    }
  } finally {
    bulkBusy.value = false
    bulk.clear()
  }
}
</script>
<style scoped>
.min-width-0 {
  min-width: 0;
}
</style>
