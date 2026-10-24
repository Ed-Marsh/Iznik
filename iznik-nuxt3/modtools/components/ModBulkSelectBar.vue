<template>
  <div
    class="bulk-bar d-flex flex-wrap align-items-center gap-2 p-2 mb-2 border"
    data-test="bulk-bar"
  >
    <div class="fw-bold" data-test="bulk-count">
      {{ count }} selected
      <span class="text-muted small fw-normal">(up to {{ limit }})</span>
    </div>
    <b-button
      variant="white"
      size="sm"
      :disabled="busy || !shownIds.length || allShownSelected"
      data-test="bulk-select-all"
      @click="$emit('select-all', shownIds)"
    >
      Select all shown
    </b-button>
    <b-button
      variant="white"
      size="sm"
      :disabled="busy || !count"
      data-test="bulk-clear"
      @click="$emit('clear')"
    >
      Clear
    </b-button>
    <b-button
      variant="secondary"
      size="sm"
      :disabled="busy || !copyRows.length"
      :title="copyTitle"
      data-test="bulk-copy"
      @click="copyList"
    >
      <v-icon v-if="!copied" icon="copy" />
      <v-icon v-else icon="check" />
      <span v-if="copied">Copied</span>
      <span v-else>Copy {{ count ? 'selected' : 'list' }}</span>
    </b-button>
    <slot />
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'

// Columns in the order they are pasted. A column every row lacks is left out, so
// a list of plain members has no empty reason column.
const COLUMNS = ['id', 'name', 'email', 'reason', 'added', 'by']

const props = defineProps({
  count: {
    type: Number,
    required: true,
  },
  limit: {
    type: Number,
    required: true,
  },
  // Ids of the rows on screen that may be selected.
  shownIds: {
    type: Array,
    required: true,
  },
  // Rows to put on the clipboard: the selected ones, or everything shown when
  // nothing is selected. Each is { id, name, email, reason?, added?, by? }.
  copyRows: {
    type: Array,
    required: true,
  },
  busy: {
    type: Boolean,
    required: false,
    default: false,
  },
})

defineEmits(['select-all', 'clear'])

const copied = ref(false)

const allShownSelected = computed(
  () => props.count >= props.limit || props.count >= props.shownIds.length
)

const copyTitle = computed(() =>
  props.count
    ? 'Copy the selected members as plain text, one per line'
    : 'Copy everything shown as plain text, one per line'
)

// One member per line, fields separated by tabs, so it pastes into a spreadsheet
// as columns and into a document as readable lines. Nothing else comes with it.
function plainText(rows) {
  const columns = COLUMNS.filter((c) =>
    rows.some((r) => r[c] !== undefined && r[c] !== null && r[c] !== '')
  )

  return rows
    .map((r) =>
      columns
        .map((c) => String(r[c] ?? '').replace(/[\t\r\n]+/g, ' '))
        .join('\t')
    )
    .join('\n')
}

async function copyList() {
  const text = plainText(props.copyRows)

  if (import.meta.client) {
    if (process.env.IS_APP) {
      window.cordova.plugins.clipboard.copy(text)
    } else {
      await navigator.clipboard.writeText(text)
    }
  }

  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}

defineExpose({ plainText })
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';

.bulk-bar {
  background-color: $gray-100;
}
</style>
