import { components } from '@statamic/cms/api';

import LifecycleStatusField from './fieldtypes/LifecycleStatusField.vue';

/**
 * The addon's control panel assets.
 *
 * One fieldtype so far: the review panel in the entry sidebar. The utility
 * screens and the dashboard widget are later phases.
 */
components.register('lifecycle_status-fieldtype', LifecycleStatusField);
