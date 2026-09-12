<script setup>
import { reactive, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Badge, Button, Card, Header, Heading, Panel, PanelHeader, Select, Table, TableCell, TableRow, Text } from '@statamic/cms/ui';

/**
 * The list: every page with its owner, date and state, filtered the ways
 * spec §8 asks. With `mine`, the same list for the person looking — the
 * screen an owner actually lives in.
 */
const props = defineProps({
    title: String,
    mine: Boolean,
    installed: Boolean,
    entries: Object,
    filters: Object,
    statusMeta: Object,
    options: Object,
    dashboardUrl: String,
    entriesUrl: String,
    mineUrl: String,
});

const filters = reactive({ ...props.filters });
if (props.mine) delete filters.owner;

watch(filters, (value) => {
    const query = Object.fromEntries(Object.entries(value).filter(([, v]) => v));
    router.get(window.location.pathname, query, { preserveState: true, replace: true });
});

function day(iso) {
    if (!iso) return '';
    return new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

const cellPadding = { paddingInline: '1.125rem' };
</script>

<template>
    <Head :title="title" />

    <Header :title="title" icon="history">
        <Button :href="dashboardUrl" text="Overview" />
        <Button v-if="mine" :href="entriesUrl" text="All content" />
        <Button v-else :href="mineUrl" text="My content" />
    </Header>

    <div v-if="!installed" class="mt-6">
        <Card>
            <Heading text="Lifecycle is not installed yet" />
            <Text>Run <code>php please lifecycle:install</code>.</Text>
        </Card>
    </div>

    <Panel v-else class="mt-6">
        <PanelHeader class="flex flex-wrap gap-3">
            <Select v-model="filters.status" :options="options.statuses" placeholder="Any state" clearable />
            <Select v-model="filters.attention" :options="options.attention" placeholder="Anything" clearable />
            <Select v-model="filters.collection" :options="options.collections" placeholder="Any collection" clearable />
            <Select v-if="options.sites.length > 1" v-model="filters.site" :options="options.sites" placeholder="Any site" clearable />
        </PanelHeader>

        <div v-if="!entries.data.length" class="p-4">
            <Text v-if="mine">Nothing of yours needs attention. That is the good outcome.</Text>
            <Text v-else>Nothing matches those filters.</Text>
        </div>

        <Table v-else>
            <TableRow v-for="row in entries.data" :key="row.id">
                <TableCell :style="cellPadding">
                    <a v-if="row.edit_url" :href="row.edit_url">{{ row.title }}</a>
                    <span v-else>{{ row.title }}</span>
                    <Text as="div" size="sm" variant="subtle">{{ row.collection }}<span v-if="row.uri"> · {{ row.uri }}</span></Text>
                </TableCell>
                <TableCell :style="cellPadding">
                    <template v-if="row.owner">
                        {{ row.owner.name }}
                        <Text as="div" size="sm" variant="subtle">{{ row.owner_source_label }}</Text>
                    </template>
                    <Text v-else size="sm" variant="subtle">Nobody</Text>
                </TableCell>
                <TableCell :style="cellPadding">
                    <Badge :color="row.colour" :text="row.status_label" />
                    <Text v-if="row.next_review_at" as="div" size="sm" variant="subtle">{{ day(row.next_review_at) }}</Text>
                </TableCell>
                <TableCell :style="cellPadding">
                    <Text v-if="row.last_reviewed_at" size="sm">{{ day(row.last_reviewed_at) }}<span v-if="row.last_reviewed_by"> by {{ row.last_reviewed_by }}</span></Text>
                    <Text v-else size="sm" variant="subtle">Never reviewed</Text>
                </TableCell>
            </TableRow>
        </Table>
    </Panel>

    <div v-if="installed && entries.total" class="mt-4 flex items-center gap-3">
        <Text size="sm">{{ entries.total.toLocaleString() }} {{ entries.total === 1 ? 'page' : 'pages' }}.</Text>
        <Button v-if="entries.prev_page_url" :href="entries.prev_page_url" variant="ghost" text="Previous" />
        <Button v-if="entries.next_page_url" :href="entries.next_page_url" variant="ghost" text="Next" />
    </div>
</template>
