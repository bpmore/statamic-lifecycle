import { components, inertia } from '@statamic/cms/api';

import LifecycleStatusField from './fieldtypes/LifecycleStatusField.vue';
import Dashboard from './pages/Dashboard.vue';
import Entries from './pages/Entries.vue';

/**
 * The addon's control panel assets: the review panel in the entry sidebar,
 * and the utility screens — the overview, the list, and "My content".
 */
components.register('lifecycle_status-fieldtype', LifecycleStatusField);

inertia.register('lifecycle/Dashboard', Dashboard);
inertia.register('lifecycle/Entries', Entries);
