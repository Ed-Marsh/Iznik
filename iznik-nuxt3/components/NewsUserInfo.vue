<template>
  <nuxt-link
    no-prefetch
    :to="'/profile/' + newsfeed.userid"
    class="text-success nodecor news-user-info"
    :title="'Click to view profile for ' + newsfeed.displayname"
  >
    <span class="text-muted small">
      <span v-if="newsfeed.location" class="pl-0">
        <v-icon icon="map-marker-alt" class="ms-2" />&nbsp;{{
          newsfeed.location
        }}<span
          v-if="milesText"
          class="poster-distance"
          :title="DISTANCE_TOOLTIP"
          >&nbsp;-&nbsp;{{ milesText }}</span
        >
      </span>
      <span
        v-if="newsfeed.userinfo.openoffers + newsfeed.userinfo.openwanteds > 0"
        class="user-stats"
      >
        &bull;
        <span
          v-if="newsfeed.userinfo.openoffers"
          class="text-success stat-item"
        >
          <span class="stat-full">{{ openoffers }}</span>
          <span class="stat-compact">
            <v-icon icon="gift" class="stat-icon" />{{
              newsfeed.userinfo.openoffers
            }}
          </span>
        </span>
        <span
          v-if="newsfeed.userinfo.openwanteds"
          class="text-success stat-item"
        >
          <span class="stat-full">{{ openwanted }}</span>
          <span class="stat-compact">
            <v-icon icon="shopping-cart" class="stat-icon" />{{
              newsfeed.userinfo.openwanteds
            }}
          </span>
        </span>
      </span>
      <span v-if="newsfeed.showmod" class="volunteer-badge">
        &bull;
        <v-icon icon="leaf" /> Freegle Volunteer
      </span>
    </span>
  </nuxt-link>
</template>
<script setup>
import pluralize from 'pluralize'
import { computed, watch } from 'vue'
import { useNewsfeedStore } from '~/stores/newsfeed'
import { useUserStore } from '~/stores/user'
import { useMe } from '~/composables/useMe'
import { useMilesFromMe } from '~/composables/useMilesFromMe'
import { DISTANCE_TOOLTIP } from '~/constants'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

const newsfeedStore = useNewsfeedStore()

const userStore = useUserStore()
const { myid } = useMe()

const newsfeed = computed(() => {
  return newsfeedStore?.byId(props.id)
})

// Area names are not unique across the UK, so show how far away the poster is. Not for
// your own posts, and not for Community News / Alerts, whose "poster" is a system
// account with no real location. userStore.fetch caches and batches, so a feed page
// costs a handful of /user requests rather than one per post.
const distanceUserId = computed(() => {
  const nf = newsfeed.value
  if (!myid.value || !nf?.userid || nf.userid === myid.value) return null
  if (nf.type === 'Alert') return null
  return nf.userid
})

watch(
  distanceUserId,
  (id) => {
    if (import.meta.client && id) {
      userStore.fetch(id).catch(() => {})
    }
  },
  { immediate: true }
)

const { milesText } = useMilesFromMe(() =>
  distanceUserId.value ? userStore.byId(distanceUserId.value) : null
)

const openoffers = computed(() => {
  let ret = null

  if (newsfeed.value?.userinfo) {
    ret = pluralize('OFFER', newsfeed.value.userinfo.openoffers, true)
  }

  return ret
})

const openwanted = computed(() => {
  let ret = null

  if (newsfeed.value?.userinfo) {
    ret = pluralize('WANTED', newsfeed.value.userinfo.openwanteds, true)
  }

  return ret
})
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

// Show full text on larger screens, compact icons on mobile
.stat-full {
  display: none;

  @include media-breakpoint-up(md) {
    display: inline;
  }
}

.stat-compact {
  display: inline;
  font-size: 0.75rem;

  @include media-breakpoint-up(md) {
    display: none;
  }
}

.stat-icon {
  margin-right: 0.125rem;
  font-size: 0.7rem;
  opacity: 0.8;
}

.stat-item {
  margin-right: 0.25rem;
}

// Hide entire volunteer badge on mobile - avatar badge shows it
.volunteer-badge {
  @include media-breakpoint-down(md) {
    display: none;
  }
}
</style>
