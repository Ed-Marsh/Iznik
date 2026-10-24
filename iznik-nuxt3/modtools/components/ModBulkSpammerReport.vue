<template>
  <b-modal
    id="bulkspamreportmodal"
    ref="modal"
    :title="title"
    size="lg"
    no-stacking
  >
    <template #default>
      <p v-if="direct">
        These {{ count }} members will go straight onto the spammer list, so
        they are banned from every community.
      </p>
      <p v-else>
        These {{ count }} members will be reported to the Spam team, who will
        check them before they are added to the list.
      </p>
      <p>
        Spammers must have
        <strong>clear intent to deliberately spam/scam</strong>, or be a
        multijoiner across communities a long way apart, without a good reason.
        See
        <ExternalLink
          href="https://wiki.ilovefreegle.org/Spammers#SPAM_checking_before_submission"
        >
          the wiki
        </ExternalLink>
        for more details and advice.
      </p>
      <p>
        One reason is used for all of them. It will be visible to other
        moderators, so please make sure it's helpful.
      </p>
      <b-form-textarea
        v-model="reason"
        placeholder="Enter a reason"
        data-test="bulk-spam-reason"
      />
    </template>
    <template #footer>
      <b-button variant="white" @click="hide"> Cancel </b-button>
      <b-button
        variant="danger"
        :disabled="!reason || !reason.trim()"
        data-test="bulk-spam-send"
        @click="send"
      >
        {{ direct ? 'Add to spammer list' : 'Send report' }}
      </b-button>
    </template>
  </b-modal>
</template>
<script setup>
import { ref, computed } from 'vue'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  count: {
    type: Number,
    required: true,
  },
  // True when the moderator has Spam admin and the members are added as
  // confirmed spammers, false when they are reported for the team to check.
  direct: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const emit = defineEmits(['confirm'])

const { modal, hide } = useOurModal()

const reason = ref(null)

const title = computed(() =>
  props.direct
    ? `Add ${props.count} to spammer list`
    : `Report ${props.count} as spammers`
)

function send() {
  if (reason.value && reason.value.trim()) {
    emit('confirm', reason.value.trim())
    hide()
  }
}
</script>
