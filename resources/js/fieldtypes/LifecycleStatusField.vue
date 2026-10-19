<script setup>
import { computed, getCurrentInstance, ref } from 'vue';
import { toast } from '@statamic/cms/api';
import { Badge, Button, Select, Text, Textarea } from '@statamic/cms/ui';

/**
 * The review panel in an entry's sidebar (spec §8): where the page is in its
 * cycle and why, who owns it and how sure that is, when a person last
 * confirmed it, the history, and the "Mark reviewed" button.
 *
 * Read-only as a field. The value is the payload SidebarPanel builds; the
 * button posts to the same action the listing uses, then re-reads the
 * payload from the refresh route, so the panel never shows something it
 * worked out for itself.
 */
const props = defineProps({ value: { type: Object, default: null } });

// Statamic 6 puts its HTTP client on the Vue app, not on window.Statamic:
// it is `this.$axios` in a component, which in <script setup> is this. The
// client carries the CSRF token and the CP's error handling.
const { $axios } = getCurrentInstance().appContext.config.globalProperties;

const panel = ref(props.value);
const attesting = ref(false);
const saving = ref(false);
const puttingBack = ref(false);
const outcome = ref('current');
const note = ref('');
const errors = ref({});

const outcomeOptions = computed(() =>
    Object.entries(panel.value?.outcomes ?? {}).map(([value, label]) => ({ value, label })),
);

const cellPadding = { paddingInline: '0' };

function day(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function person(p) {
    if (!p) return '';
    return p.exists ? p.name : `${p.name} (no longer a user)`;
}

function open() {
    outcome.value = 'current';
    note.value = '';
    errors.value = {};
    attesting.value = true;
}

async function putBack() {
    puttingBack.value = true;

    try {
        const { data } = await $axios.post(panel.value.urls.attest, {
            action: 'put_back',
            selections: [panel.value.entry.id],
            values: {},
        });

        if (data.success === false) {
            toast.error(data.message ?? 'Could not put it back.');
            return;
        }

        toast.success(data.message ?? 'Put back.');
        const fresh = await $axios.get(panel.value.urls.refresh);
        panel.value = fresh.data;
    } catch (e) {
        toast.error(e.response?.data?.message ?? 'Could not put it back.');
    } finally {
        puttingBack.value = false;
    }
}

async function submit() {
    saving.value = true;
    errors.value = {};

    try {
        const { data } = await $axios.post(panel.value.urls.attest, {
            action: 'mark_reviewed',
            selections: [panel.value.entry.id],
            values: { outcome: outcome.value, note: note.value },
        });

        if (data.success === false) {
            toast.error(data.message ?? 'Could not record the review.');
            return;
        }

        toast.success(data.message ?? 'Marked as reviewed.');
        attesting.value = false;

        const fresh = await $axios.get(panel.value.urls.refresh);
        panel.value = fresh.data;
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {};
        } else {
            toast.error(e.response?.data?.message ?? 'Could not record the review.');
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div v-if="!panel || !panel.entry">
        <Text size="sm" variant="subtle">Save the page first. Its review cycle starts once it exists.</Text>
    </div>

    <div v-else-if="!panel.installed">
        <Text size="sm" variant="subtle">Lifecycle is not installed yet. Run <code>php please lifecycle:install</code>.</Text>
    </div>

    <div v-else class="space-y-4">
        <!-- Status and next review -->
        <div v-if="panel.state" class="space-y-1">
            <div class="flex items-center gap-2">
                <Badge :color="panel.state.colour" :text="panel.state.status_label" />
                <Text v-if="panel.state.next_review_at" size="sm">
                    Next review {{ day(panel.state.next_review_at) }}
                </Text>
            </div>
            <Text v-if="panel.state.next_review_reason" as="div" size="sm" variant="subtle">
                From {{ panel.state.next_review_reason }}<span v-if="panel.state.interval_label">; {{ panel.state.interval_label.toLowerCase() }}, {{ panel.state.interval_reason }}</span>.
            </Text>
            <Text v-if="panel.state.status === 'exempt'" as="div" size="sm" variant="subtle">
                Outside the review cycle: no review date, no reminders, no expiry action. Turn off <em>Exempt from review</em> above to bring it back.
            </Text>
        </div>
        <div v-else>
            <Text size="sm" variant="subtle">
                <template v-if="!panel.entry.published">Not published, so not in the review cycle.</template>
                <template v-else>Not computed yet. The nightly recompute will pick it up, or run <code>php please lifecycle:recompute</code>.</template>
            </Text>
        </div>

        <!-- What Lifecycle changed, and the one click that undoes it -->
        <div v-if="panel.last_action" class="space-y-2">
            <Text as="div" size="sm" class="font-medium">Lifecycle changed this page</Text>
            <Text as="div" size="sm">
                It chose to {{ panel.last_action.label }} on {{ day(panel.last_action.applied_at) }}, because the page stayed overdue.
            </Text>
            <Button v-if="panel.can_put_back" variant="ghost" :loading="puttingBack" :disabled="puttingBack" @click="putBack">Put it back</Button>
        </div>

        <!-- Owner -->
        <div v-if="panel.state">
            <Text as="div" size="sm" class="font-medium">Owner</Text>
            <Text v-if="panel.state.owner" as="div" size="sm">
                {{ person(panel.state.owner) }}
                <span class="text-gray-500"> — {{ panel.state.owner.source_label }}</span>
            </Text>
            <Text v-else as="div" size="sm" variant="subtle">Nobody. Set a content owner above.</Text>
        </div>

        <!-- Last review -->
        <div v-if="panel.state">
            <Text as="div" size="sm" class="font-medium">Last reviewed</Text>
            <Text v-if="panel.state.last_reviewed_at" as="div" size="sm">
                {{ day(panel.state.last_reviewed_at) }} by {{ person(panel.state.last_reviewed_by) }}
            </Text>
            <Text v-else as="div" size="sm" variant="subtle">Never. Nobody has confirmed this page yet.</Text>
        </div>

        <!-- Mark reviewed -->
        <div v-if="panel.can_attest && panel.entry.published">
            <Button v-if="!attesting" @click="open">Mark reviewed</Button>

            <div v-else class="space-y-3">
                <Text as="div" size="sm">You are confirming this page is accurate as it stands. That goes on the record with your name and today's date.</Text>

                <div>
                    <Text as="div" size="sm" class="font-medium">What did you find?</Text>
                    <Select v-model="outcome" :options="outcomeOptions" option-label="label" option-value="value" />
                    <Text v-if="errors.outcome" as="div" size="sm" class="text-red-500">{{ errors.outcome[0] }}</Text>
                </div>

                <div>
                    <Text as="div" size="sm" class="font-medium">Note <span class="text-gray-500">(optional)</span></Text>
                    <Textarea v-model="note" rows="3" placeholder="Anything the next reviewer, or an auditor, should know." />
                    <Text v-if="errors.note" as="div" size="sm" class="text-red-500">{{ errors.note[0] }}</Text>
                </div>

                <div class="flex gap-2">
                    <Button variant="primary" :loading="saving" :disabled="saving" @click="submit">Record review</Button>
                    <Button variant="ghost" :disabled="saving" @click="attesting = false">Cancel</Button>
                </div>
            </div>
        </div>

        <!-- History -->
        <div v-if="panel.timeline">
            <Text as="div" size="sm" class="font-medium">History</Text>
            <Text v-if="panel.timeline.count === 0" as="div" size="sm" variant="subtle">No reviews recorded.</Text>
            <ul v-else class="space-y-2">
                <li v-for="review in panel.timeline.reviews" :key="review.id">
                    <Text as="div" size="sm">
                        <span class="font-medium">{{ day(review.reviewed_at) }}</span>
                        · {{ review.reviewer_exists ? review.reviewer_name : `${review.reviewer_name} (no longer a user)` }}
                        · {{ review.outcome_label }}<span v-if="review.source === 'edit'"> · {{ review.source_label }}</span>
                    </Text>
                    <Text v-if="review.note" as="div" size="sm" variant="subtle">{{ review.note }}</Text>
                    <Text v-if="review.content_changed" as="div" size="sm" variant="subtle">The content had changed since the previous review.</Text>
                </li>
            </ul>
        </div>
    </div>
</template>
