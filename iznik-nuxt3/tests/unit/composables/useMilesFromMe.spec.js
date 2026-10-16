import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { useMilesFromMe } from '~/composables/useMilesFromMe'

const mockMe = ref(null)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

describe('useMilesFromMe', () => {
  beforeEach(() => {
    mockMe.value = { id: 1, lat: 53.4, lng: -2.99 } // Liverpool
  })

  it('rounds to whole miles above 2 miles', () => {
    // Roughly Liverpool to Leeds
    const { miles, milesText } = useMilesFromMe({ lat: 53.8, lng: -1.55 })
    expect(Number.isInteger(miles.value)).toBe(true)
    expect(miles.value).toBeGreaterThan(50)
    expect(milesText.value).toBe(miles.value + ' miles')
  })

  it('uses one decimal place under 2 miles', () => {
    const { miles, milesText } = useMilesFromMe({ lat: 53.4058, lng: -2.99 })
    expect(miles.value).toBeCloseTo(0.4, 1)
    expect(milesText.value).toBe('0.4 miles')
  })

  it('says "1 mile" in the singular', () => {
    const { milesText } = useMilesFromMe({ lat: 53.4145, lng: -2.99 })
    expect(milesText.value).toBe('1 mile')
  })

  it('is null when the other member has no coordinates', () => {
    const { miles, milesText } = useMilesFromMe({ lat: 0, lng: 0 })
    expect(miles.value).toBeNull()
    expect(milesText.value).toBeNull()
  })

  it('is null when the other member is not loaded', () => {
    expect(useMilesFromMe(undefined).miles.value).toBeNull()
  })

  it('is null when the viewer has no coordinates', () => {
    mockMe.value = { id: 1 }
    expect(useMilesFromMe({ lat: 53.8, lng: -1.55 }).miles.value).toBeNull()
  })

  it('is null when not logged in', () => {
    mockMe.value = null
    expect(useMilesFromMe({ lat: 53.8, lng: -1.55 }).miles.value).toBeNull()
  })

  it('follows a ref as the user loads', () => {
    const user = ref(null)
    const { miles } = useMilesFromMe(user)
    expect(miles.value).toBeNull()
    user.value = { lat: 53.8, lng: -1.55 }
    expect(miles.value).toBeGreaterThan(50)
  })
})
