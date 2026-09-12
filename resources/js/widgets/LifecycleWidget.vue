<script setup>
import { computed } from 'vue';
import { Badge, Text, Widget } from '@statamic/cms/ui';

/**
 * The dashboard widget: my overdue count, the site's, and the thirty-day
 * trend as a sparkline drawn here, so no chart library rides along.
 */
const props = defineProps({
    title: String,
    installed: Boolean,
    mine: Object,
    site: Object,
    trend: Array,
    change: Number,
    since: Number,
    days: Number,
    dashboardUrl: String,
    mineUrl: String,
    overdueUrl: String,
});

const width = 240;
const height = 48;

// Points sit where their day falls, so a night the recompute skipped shows as a gap, not a lie.
const points = computed(() => {
    const series = props.trend ?? [];
    if (series.length < 2) return '';
    const day = (p) => Date.parse(`${p.day}T00:00:00Z`) / 86_400_000;
    const first = day(series[0]);
    const span = Math.max(1, day(series[series.length - 1]) - first);
    const max = Math.max(1, ...series.map((p) => p.late));
    return series
        .map((p) => `${(((day(p) - first) / span) * width).toFixed(1)},${(height - 4 - (p.late / max) * (height - 8)).toFixed(1)}`)
        .join(' ');
});

// Against the oldest snapshot in the window, which is only "30 days ago" once there are 30 of them.
const changeText = computed(() => {
    if (props.change === null || props.change === undefined) return null;
    const ago = props.since === 1 ? 'yesterday' : `${props.since} days ago`;
    if (props.change === 0) return `No change since ${ago}`;
    const n = Math.abs(props.change);
    return `${props.change < 0 ? '↓' : '↑'} ${n} ${props.change < 0 ? 'fewer' : 'more'} overdue than ${ago}`;
});
</script>

<template>
    <Widget :title="title" icon="history" :href="dashboardUrl">
        <div v-if="!installed" class="p-4">
            <Text size="sm" variant="subtle">Lifecycle is not installed yet. Run <code>php please lifecycle:install</code>.</Text>
        </div>

        <div v-else class="p-4 space-y-4">
            <div class="flex flex-wrap gap-6">
                <a v-if="mine" :href="mineUrl" class="block">
                    <Text as="div" size="sm" variant="subtle">Yours</Text>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-semibold">{{ mine.overdue.toLocaleString() }}</span>
                        <Text size="sm">overdue</Text>
                        <Badge v-if="mine.due_soon" color="amber" :text="`${mine.due_soon.toLocaleString()} due soon`" />
                    </div>
                </a>
                <a v-if="site" :href="overdueUrl" class="block">
                    <Text as="div" size="sm" variant="subtle">Whole site</Text>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-semibold">{{ site.overdue.toLocaleString() }}</span>
                        <Text size="sm">overdue of {{ site.total.toLocaleString() }} tracked</Text>
                    </div>
                </a>
            </div>

            <div v-if="site">
                <svg v-if="points" :viewBox="`0 0 ${width} ${height}`" :width="width" :height="height" role="img" :aria-label="`Overdue pages over the last ${days} days`" class="max-w-full">
                    <polyline :points="points" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                </svg>
                <Text v-if="changeText" as="div" size="sm" variant="subtle">{{ changeText }}</Text>
                <Text v-else-if="!points" as="div" size="sm" variant="subtle">The trend appears after a few nightly recomputes.</Text>
            </div>
        </div>
    </Widget>
</template>
