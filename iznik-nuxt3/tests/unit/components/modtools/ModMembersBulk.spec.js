import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModMembers from '~/modtools/components/ModMembers.vue'

// The real component, with its list and stores mocked, to check the bulk
// spammer action that sits above the member cards.

const mockFilter = ref('0')
const mockVisibleMembers = ref([])
const mockSpamAdmin = ref(true)
const mockAddMany = vi.fn()
const mockUserList = {}

vi.mock('~/composables/useModMembers', () => ({
  setupModMembers: () => ({
    filter: mockFilter,
    visibleMembers: mockVisibleMembers,
  }),
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({ hasPermissionSpamAdmin: mockSpamAdmin }),
}))

vi.mock('~/stores/spammer', () => ({
  useSpammerStore: () => ({ addMany: mockAddMany }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ list: mockUserList }),
}))

const stubs = {
  ModMember: {
    template: '<div class="member-stub" />',
    props: ['membershipid'],
  },
  ModBulkSelectBar: {
    template: '<div class="bulk-bar-stub" :data-count="count"><slot /></div>',
    props: ['count', 'limit', 'shownIds', 'copyRows', 'busy'],
    emits: ['select-all', 'clear'],
  },
  ModBulkSpammerReport: {
    template: '<div class="bulk-modal-stub" :data-direct="direct" />',
    props: ['count', 'direct'],
    emits: ['confirm', 'hidden'],
  },
  NoticeMessage: {
    template: '<div class="notice-stub"><slot /></div>',
    props: ['variant'],
  },
  'b-form-checkbox': {
    template:
      '<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', !modelValue)" />',
    props: ['modelValue', 'disabled', 'title'],
    emits: ['update:modelValue'],
  },
  'b-button': {
    template:
      '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant', 'size', 'disabled'],
    emits: ['click'],
  },
  'v-icon': { template: '<i />', props: ['icon'] },
}

const members = [
  {
    id: 11,
    userid: 1,
    groupid: 100,
    displayname: 'Eric Jones',
    email: 'eric1@example.com',
    added: '2026-10-20T09:00:00Z',
  },
  // The same person on a second community: one checkbox state, one row to copy.
  {
    id: 12,
    userid: 1,
    groupid: 101,
    displayname: 'Eric Jones',
    email: 'eric1@example.com',
    added: '2026-10-21T09:00:00Z',
  },
  {
    id: 13,
    userid: 2,
    groupid: 100,
    displayname: 'Eric Jones',
    email: 'eric2@example.com',
    joined: '2026-10-22T09:00:00Z',
  },
]

function mountIt() {
  return mount(ModMembers, { global: { stubs } })
}

describe('ModMembers bulk spammer action', () => {
  beforeEach(() => {
    mockFilter.value = '0'
    mockVisibleMembers.value = members
    mockSpamAdmin.value = true
    mockAddMany.mockReset()
    mockAddMany.mockResolvedValue({ done: [], failed: [] })
  })

  it('shows no bar with nothing listed', () => {
    mockVisibleMembers.value = []
    const w = mountIt()
    expect(w.find('.bulk-bar-stub').exists()).toBe(false)
  })

  it('keys selection by person, so one tick covers both memberships', async () => {
    const w = mountIt()
    expect(w.vm.shownUserIds).toEqual([1, 2])
    await w.find('[data-test="bulk-check-1"]').trigger('change')
    expect(w.vm.bulk.count.value).toBe(1)
    const boxes = w.findAll('[data-test="bulk-check-1"]')
    expect(boxes).toHaveLength(2)
    boxes.forEach((b) => expect(b.element.checked).toBe(true))
  })

  it('labels the action as a direct add for a Spam admin and a report otherwise', async () => {
    const w = mountIt()
    expect(w.find('[data-test="bulk-spam"]').text()).toContain(
      'Add 0 to spammer list'
    )
    mockSpamAdmin.value = false
    await w.vm.$nextTick()
    expect(w.find('[data-test="bulk-spam"]').text()).toContain(
      'Report 0 as spammers'
    )
  })

  it('opens the reason modal and adds every selected person with that reason', async () => {
    const w = mountIt()
    mockAddMany.mockResolvedValue({ done: [1, 2], failed: [] })
    w.vm.bulk.selectAll([1, 2])
    await w.vm.$nextTick()
    await w.find('[data-test="bulk-spam"]').trigger('click')
    await w.vm.$nextTick()
    const modal = w.find('.bulk-modal-stub')
    expect(modal.exists()).toBe(true)
    expect(modal.attributes('data-direct')).toBe('true')

    w.findComponent('.bulk-modal-stub').vm.$emit(
      'confirm',
      'Same name, many accounts'
    )
    await flushPromises()

    expect(mockAddMany).toHaveBeenCalledWith({
      userids: [1, 2],
      reason: 'Same name, many accounts',
      collection: 'Spammer',
    })
    expect(w.vm.bulkResult).toEqual({ direct: true, done: 2, failed: [] })
    expect(w.find('[data-test="bulk-result"]').text()).toContain(
      'Added 2 to the spammer list'
    )
    expect(w.vm.bulk.count.value).toBe(0)
  })

  it('reports as Pending Add for a moderator without Spam admin', async () => {
    mockSpamAdmin.value = false
    const w = mountIt()
    mockAddMany.mockResolvedValue({ done: [2], failed: [] })
    w.vm.bulk.toggle(2)
    await w.vm.bulkSpam('Looks like a bot')
    expect(mockAddMany).toHaveBeenCalledWith({
      userids: [2],
      reason: 'Looks like a bot',
      collection: 'PendingAdd',
    })
    expect(w.find('[data-test="bulk-result"]').text()).toContain(
      'Reported 1 to the Spam team'
    )
  })

  it('names the people that could not be added', async () => {
    const w = mountIt()
    mockAddMany.mockResolvedValue({
      done: [1],
      failed: [{ item: 2, error: new Error('Server error') }],
    })
    w.vm.bulk.selectAll([1, 2])
    await w.vm.bulkSpam('r')
    expect(w.vm.bulkResult.failed).toEqual([
      { userid: 2, name: 'Eric Jones', message: 'Server error' },
    ])
    expect(w.find('[data-test="bulk-result"]').text()).toContain('#2')
  })

  it('copies the selected people, or everyone shown, as plain fields', async () => {
    const w = mountIt()
    expect(w.vm.copyRows).toEqual([
      {
        id: 1,
        name: 'Eric Jones',
        email: 'eric1@example.com',
        added: '2026-10-20',
      },
      {
        id: 2,
        name: 'Eric Jones',
        email: 'eric2@example.com',
        added: '2026-10-22',
      },
    ])
    w.vm.bulk.toggle(2)
    await w.vm.$nextTick()
    expect(w.vm.copyRows.map((r) => r.id)).toEqual([2])
  })

  it('does nothing when confirmed with nothing selected', async () => {
    const w = mountIt()
    await w.vm.bulkSpam('r')
    expect(mockAddMany).not.toHaveBeenCalled()
  })
})
