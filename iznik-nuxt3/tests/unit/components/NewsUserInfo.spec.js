import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, computed } from 'vue'
import NewsUserInfo from '~/components/NewsUserInfo.vue'

const mockNewsfeed = vi.fn()

vi.mock('~/stores/newsfeed', () => ({
  useNewsfeedStore: () => ({
    byId: mockNewsfeed,
  }),
}))

const mockFetch = vi.fn()
const mockUserById = vi.fn()

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    fetch: mockFetch,
    byId: mockUserById,
  }),
}))

const mockMe = ref(null)

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    myid: computed(() => mockMe.value?.id),
  }),
}))

describe('NewsUserInfo', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetch.mockResolvedValue(undefined)
    mockUserById.mockReturnValue(undefined)
    mockMe.value = null
    mockNewsfeed.mockReturnValue({
      userid: 1,
      displayname: 'Test User',
      location: 'London',
      userinfo: { openoffers: 0, openwanteds: 0 },
      showmod: false,
    })
  })

  function createWrapper(props = {}) {
    return mount(NewsUserInfo, {
      props: {
        id: 1,
        ...props,
      },
      global: {
        stubs: {
          'nuxt-link': {
            template:
              '<a :href="to" :title="title" :class="$attrs.class"><slot /></a>',
            props: ['to', 'noPrefetch', 'title'],
          },
          'v-icon': {
            template: '<span class="v-icon" :data-icon="icon" />',
            props: ['icon'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders link to user profile', () => {
      mockNewsfeed.mockReturnValue({
        userid: 123,
        displayname: 'Test User',
        userinfo: { openoffers: 0, openwanteds: 0 },
      })
      const wrapper = createWrapper()
      expect(wrapper.find('a').attributes('href')).toBe('/profile/123')
    })

    it('has title with user displayname', () => {
      mockNewsfeed.mockReturnValue({
        userid: 1,
        displayname: 'John Doe',
        userinfo: { openoffers: 0, openwanteds: 0 },
      })
      const wrapper = createWrapper()
      expect(wrapper.find('a').attributes('title')).toContain('John Doe')
    })
  })

  describe('location display', () => {
    it('shows location when provided', () => {
      mockNewsfeed.mockReturnValue({
        userid: 1,
        location: 'Manchester',
        userinfo: { openoffers: 0, openwanteds: 0 },
      })
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Manchester')
      expect(wrapper.find('.v-icon[data-icon="map-marker-alt"]').exists()).toBe(
        true
      )
    })

    it('hides location when not provided', () => {
      mockNewsfeed.mockReturnValue({
        userid: 1,
        location: null,
        userinfo: { openoffers: 0, openwanteds: 0 },
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.v-icon[data-icon="map-marker-alt"]').exists()).toBe(
        false
      )
    })
  })

  describe('offers and wanteds display', () => {
    it('shows offers count when has open offers', () => {
      mockNewsfeed.mockReturnValue({
        userid: 1,
        userinfo: { openoffers: 3, openwanteds: 0 },
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.v-icon[data-icon="gift"]').exists()).toBe(true)
    })

    it('shows wanteds count when has open wanteds', () => {
      mockNewsfeed.mockReturnValue({
        userid: 1,
        userinfo: { openoffers: 0, openwanteds: 5 },
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.v-icon[data-icon="shopping-cart"]').exists()).toBe(
        true
      )
    })

    it('hides stats when no offers or wanteds', () => {
      mockNewsfeed.mockReturnValue({
        userid: 1,
        userinfo: { openoffers: 0, openwanteds: 0 },
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.user-stats').exists()).toBe(false)
    })

    it('shows both offers and wanteds', () => {
      mockNewsfeed.mockReturnValue({
        userid: 1,
        userinfo: { openoffers: 2, openwanteds: 3 },
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.v-icon[data-icon="gift"]').exists()).toBe(true)
      expect(wrapper.find('.v-icon[data-icon="shopping-cart"]').exists()).toBe(
        true
      )
    })
  })

  describe('volunteer badge', () => {
    it('shows volunteer badge when showmod is true', () => {
      mockNewsfeed.mockReturnValue({
        userid: 1,
        userinfo: { openoffers: 0, openwanteds: 0 },
        showmod: true,
      })
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Freegle Volunteer')
      expect(wrapper.find('.v-icon[data-icon="leaf"]').exists()).toBe(true)
    })

    it('hides volunteer badge when showmod is false', () => {
      mockNewsfeed.mockReturnValue({
        userid: 1,
        userinfo: { openoffers: 0, openwanteds: 0 },
        showmod: false,
      })
      const wrapper = createWrapper()
      expect(wrapper.text()).not.toContain('Freegle Volunteer')
    })
  })

  describe('props', () => {
    it('requires id prop', () => {
      const wrapper = createWrapper({ id: 10 })
      expect(wrapper.props('id')).toBe(10)
    })
  })

  describe('distance to poster', () => {
    // Viewer in Liverpool.
    const liverpool = { id: 99, lat: 53.4, lng: -2.99 }

    function post(extra = {}) {
      mockNewsfeed.mockReturnValue({
        userid: 5,
        displayname: 'Poster',
        location: 'Fiveways',
        userinfo: { openoffers: 0, openwanteds: 0 },
        showmod: false,
        ...extra,
      })
    }

    beforeEach(() => {
      mockMe.value = liverpool
      mockUserById.mockReturnValue({ id: 5, lat: 51.5, lng: -0.12 }) // London
    })

    it('shows whole miles for a far poster', () => {
      post()
      const wrapper = createWrapper()
      expect(wrapper.text()).toMatch(/Fiveways\s*-\s*1\d\d miles/)
    })

    it('shows whole miles for a nearby poster', () => {
      post()
      mockUserById.mockReturnValue({ id: 5, lat: 53.44, lng: -2.99 })
      expect(createWrapper().text()).toMatch(/Fiveways\s*-\s*3 miles/)
    })

    it('shows one decimal place under 2 miles', () => {
      post()
      mockUserById.mockReturnValue({ id: 5, lat: 53.4058, lng: -2.99 })
      expect(createWrapper().text()).toMatch(/Fiveways\s*-\s*0\.4 miles/)
    })

    it('uses the singular for 1 mile', () => {
      post()
      mockUserById.mockReturnValue({ id: 5, lat: 53.4145, lng: -2.99 })
      const text = createWrapper().text()
      expect(text).toMatch(/Fiveways\s*-\s*1 mile(?!s)/)
    })

    it('puts the crow-flies tooltip on the distance', () => {
      post()
      const el = createWrapper().find('.poster-distance')
      expect(el.attributes('title')).toContain('as the crow flies')
    })

    it('keeps the stats and volunteer badge alongside', () => {
      post({ userinfo: { openoffers: 1, openwanteds: 2 }, showmod: true })
      const text = createWrapper().text()
      expect(text).toMatch(/miles/)
      expect(text).toContain('1 OFFER')
      expect(text).toContain('2 WANTEDS')
      expect(text).toContain('Freegle Volunteer')
    })

    function expectAreaOnly(wrapper) {
      expect(wrapper.find('.poster-distance').exists()).toBe(false)
      expect(wrapper.text()).toContain('Fiveways')
      expect(wrapper.text()).not.toMatch(/miles?\b/)
    }

    it('area only when the poster has no coordinates', () => {
      post()
      mockUserById.mockReturnValue({ id: 5, lat: 0, lng: 0 })
      expectAreaOnly(createWrapper())
    })

    it('area only when the poster record has not loaded', () => {
      post()
      mockUserById.mockReturnValue(undefined)
      expectAreaOnly(createWrapper())
    })

    it('area only when the viewer has no coordinates', () => {
      post()
      mockMe.value = { id: 99 }
      expectAreaOnly(createWrapper())
    })

    it('area only and no fetch when logged out', () => {
      post()
      mockMe.value = null
      expectAreaOnly(createWrapper())
      expect(mockFetch).not.toHaveBeenCalled()
    })

    it('area only and no fetch for your own post', () => {
      post({ userid: 99 })
      expectAreaOnly(createWrapper())
      expect(mockFetch).not.toHaveBeenCalled()
    })

    it('area only and no fetch for an alert', () => {
      post({ type: 'Alert' })
      expectAreaOnly(createWrapper())
      expect(mockFetch).not.toHaveBeenCalled()
    })
  })

  describe('poster fetch', () => {
    it('asks the batching user store for the poster once', async () => {
      mockMe.value = { id: 99, lat: 53.4, lng: -2.99 }
      mockNewsfeed.mockReturnValue({
        userid: 5,
        location: 'Fiveways',
        userinfo: { openoffers: 0, openwanteds: 0 },
      })
      const wrapper = createWrapper()
      await wrapper.vm.$nextTick()
      expect(mockFetch).toHaveBeenCalledTimes(1)
      expect(mockFetch).toHaveBeenCalledWith(5)
    })

    it('survives a failed fetch', async () => {
      mockMe.value = { id: 99, lat: 53.4, lng: -2.99 }
      mockFetch.mockRejectedValue(new Error('nope'))
      mockNewsfeed.mockReturnValue({
        userid: 5,
        location: 'Fiveways',
        userinfo: { openoffers: 0, openwanteds: 0 },
      })
      const wrapper = createWrapper()
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('Fiveways')
    })
  })
})
