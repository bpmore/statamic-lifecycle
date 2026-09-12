<script setup>
import { computed } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Badge, Button, Card, Header, Heading, Panel, PanelHeader, Table, TableCell, TableRow, Text } from '@statamic/cms/ui';

/**
 * The utility screen (spec §8): how much content is in what state, by
 * collection, by site and by owner, each number a way into the list.
 */
const props = defineProps({
    installed: Boolean,
    total: Number,
    statuses: Object,
    attention: Object,
    collections: Array,
    sites: Array,
    owners: Array,
    statusMeta: Object,
    entriesUrl: String,
    mineUrl: String,
    me: String,
});

const order = ['overdue', 'expired', 'due_soon', 'fresh', 'exempt'];
const headline = computed(() => {
    const late = (props.statuses.overdue ?? 0) + (props.statuses.expired ?? 0);
    const pages = `${props.total.toLocaleString()} ${props.total === 1 ? 'page' : 'pages'}`;
    if (props.total === 0) return 'Nothing is tracked yet.';
    if (late === 0) return `${pages} tracked, none overdue.`;
    return `${late.toLocaleString()} of ${pages} ${late === 1 && props.total === 1 ? 'is' : 'are'} overdue for review.`;
});

function list(params) {
    const query = Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
    return query ? `${props.entriesUrl}?${query}` : props.entriesUrl;
}

// Statamic's PanelHeader is px-4.5 (18px); its table cells carry no
// horizontal padding, so rows sit 18px left of the heading without this.
// Inline, because this addon's templates are outside Statamic's Tailwind scan.
const cellPadding = { paddingInline: '1.125rem' };
</script>

<template>
    <Head title="Lifecycle" />

    <Header title="Lifecycle" icon="history">
        <Button :href="mineUrl" text="My content" />
        <Button :href="entriesUrl" variant="primary" text="All content" />
    </Header>

    <div v-if="!installed" class="mt-6">
        <Card>
            <Heading text="Lifecycle is not installed yet" />
            <Text>Run <code>php please lifecycle:install</code>, then <code>php please lifecycle:baseline --dry-run</code>.</Text>
        </Card>
    </div>

    <div v-else-if="total === 0" class="mt-6">
        <Card>
            <Heading text="Nothing is tracked yet" />
            <Text>Run <code>php please lifecycle:baseline --dry-run</code> to see how existing content would be scheduled, then without <code>--dry-run</code> to schedule it.</Text>
        </Card>
    </div>

    <template v-else>
        <Text as="p" class="mt-4">{{ headline }}</Text>

        <!-- Status counts, each a way into the list -->
        <div class="mt-4 flex flex-wrap gap-2">
            <a v-for="status in order" :key="status" :href="list({ status })">
                <Badge :color="statusMeta[status].colour" :text="`${statusMeta[status].label} ${(statuses[status] ?? 0).toLocaleString()}`" />
            </a>
        </div>

        <!-- The filters spec §8 names -->
        <div class="mt-3 flex flex-wrap gap-2">
            <a :href="list({ attention: 'never_reviewed' })"><Badge color="default" :text="`Never reviewed ${(attention.never_reviewed ?? 0).toLocaleString()}`" /></a>
            <a :href="list({ attention: 'no_owner' })"><Badge color="default" :text="`No owner ${(attention.no_owner ?? 0).toLocaleString()}`" /></a>
            <a :href="list({ attention: 'inferred_owner' })"><Badge color="default" :text="`Owner is a guess ${(attention.inferred_owner ?? 0).toLocaleString()}`" /></a>
        </div>

        <Panel class="mt-6">
            <PanelHeader>By collection</PanelHeader>
            <Table>
                <TableRow v-for="row in collections" :key="row.key">
                    <TableCell :style="cellPadding"><a :href="list({ collection: row.key })">{{ row.label }}</a></TableCell>
                    <TableCell :style="cellPadding">{{ row.total.toLocaleString() }} tracked</TableCell>
                    <TableCell :style="cellPadding"><Badge v-if="row.overdue + row.expired" color="red" :text="`${(row.overdue + row.expired).toLocaleString()} overdue`" /></TableCell>
                    <TableCell :style="cellPadding"><Badge v-if="row.due_soon" color="amber" :text="`${row.due_soon.toLocaleString()} due soon`" /></TableCell>
                    <TableCell :style="cellPadding"><Text size="sm" variant="subtle">{{ row.never_reviewed.toLocaleString() }} never reviewed</Text></TableCell>
                </TableRow>
            </Table>
        </Panel>

        <Panel v-if="sites.length > 1" class="mt-6">
            <PanelHeader>By site</PanelHeader>
            <Table>
                <TableRow v-for="row in sites" :key="row.key">
                    <TableCell :style="cellPadding"><a :href="list({ site: row.key })">{{ row.label }}</a></TableCell>
                    <TableCell :style="cellPadding">{{ row.total.toLocaleString() }} tracked</TableCell>
                    <TableCell :style="cellPadding"><Badge v-if="row.overdue + row.expired" color="red" :text="`${(row.overdue + row.expired).toLocaleString()} overdue`" /></TableCell>
                    <TableCell :style="cellPadding"><Badge v-if="row.due_soon" color="amber" :text="`${row.due_soon.toLocaleString()} due soon`" /></TableCell>
                    <TableCell :style="cellPadding"><Text size="sm" variant="subtle">{{ row.never_reviewed.toLocaleString() }} never reviewed</Text></TableCell>
                </TableRow>
            </Table>
        </Panel>

        <Panel class="mt-6">
            <PanelHeader>By owner</PanelHeader>
            <Table>
                <TableRow v-for="row in owners" :key="row.key ?? 'nobody'">
                    <TableCell :style="cellPadding">
                        <a v-if="row.key" :href="row.key === me ? mineUrl : list({ owner: row.key })">{{ row.label }}<span v-if="row.key === me"> (you)</span></a>
                        <a v-else :href="list({ attention: 'no_owner' })">{{ row.label }}</a>
                    </TableCell>
                    <TableCell :style="cellPadding">{{ row.total.toLocaleString() }} tracked</TableCell>
                    <TableCell :style="cellPadding"><Badge v-if="row.overdue + row.expired" color="red" :text="`${(row.overdue + row.expired).toLocaleString()} overdue`" /></TableCell>
                    <TableCell :style="cellPadding"><Badge v-if="row.due_soon" color="amber" :text="`${row.due_soon.toLocaleString()} due soon`" /></TableCell>
                    <TableCell :style="cellPadding"><Text size="sm" variant="subtle">{{ row.never_reviewed.toLocaleString() }} never reviewed</Text></TableCell>
                </TableRow>
            </Table>
        </Panel>
    </template>
</template>
