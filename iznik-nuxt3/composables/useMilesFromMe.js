import { computed, toValue } from 'vue'
import pluralize from 'pluralize'
import { milesAway } from '~/composables/useDistance'
import { useMe } from '~/composables/useMe'

// Crow-flies distance in miles from the logged-in member to another member, rounded
// exactly as the profile page rounds it (milesAway). Null when either end has no
// coordinates or nobody is logged in. Deliberately no road lookup: that would cost a
// /drivedistance call per member, which is too many for a feed page.
//
// `user` is a ref/getter/plain value for the other member's user record. The server
// blurs other members' lat/lng (RoadBlur, BLUR_USER) before they reach the client.
export function useMilesFromMe(user) {
  const { me } = useMe()

  const miles = computed(() => {
    const them = toValue(user)
    if (!me.value || !them) return null
    return milesAway(me.value.lat, me.value.lng, them.lat, them.lng)
  })

  // "3 miles", "1 mile", "0.4 miles", or null.
  const milesText = computed(() =>
    miles.value ? pluralize('mile', miles.value, true) : null
  )

  return { miles, milesText }
}
