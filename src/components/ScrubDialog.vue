<!--
  - SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Report, ScrubResult } from '../services/api.ts'

import { mdiCheckCircleOutline, mdiOpenInNew } from '@mdi/js'
import { formatFileSize } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { describe, mapLink } from '../findings.ts'
import { analyze, scrub } from '../services/api.ts'

const props = defineProps<{
	fileId: number
	fileName: string
	canEdit: boolean
}>()

const emit = defineEmits<{
	close: [result?: ScrubResult]
}>()

const report = ref<Report | null>(null)
/** Set once metadata was removed; handed back when the dialog has finished closing. */
let result: ScrubResult | undefined
const error = ref('')
const loading = ref(true)

const findings = computed(() => (report.value?.findings ?? [])
	.map((finding) => ({ ...finding, ...describe(finding.type) }))
	.sort((a, b) => b.weight - a.weight))

const sensitive = computed(() => findings.value.some((f) => f.type === 'location' || f.type === 'serial'))

onMounted(async () => {
	try {
		report.value = await analyze(props.fileId)
	} catch (e) {
		error.value = (e as Error).message
	} finally {
		loading.value = false
	}
})

/**
 * Remove the metadata. Returning false keeps the dialog open to show the error.
 */
async function onScrub(): Promise<false | void> {
	error.value = ''
	try {
		result = await scrub(props.fileId)
	} catch (e) {
		error.value = (e as Error).message
		return false
	}
}

// Using NcDialog's own buttons lets it run its close sequence (animation and
// focus-trap release) before we report back through `close`.
const buttons = computed(() => {
	if (!report.value || report.value.clean) {
		return [{ label: t('metascrub', 'Close') }]
	}
	return [
		{ label: t('metascrub', 'Cancel') },
		{
			label: t('metascrub', 'Remove metadata'),
			variant: 'primary' as const,
			disabled: !props.canEdit,
			callback: onScrub,
		},
	]
})
</script>

<template>
	<NcDialog
		:name="t('metascrub', 'Remove metadata')"
		size="normal"
		content-classes="metascrub-dialog"
		:buttons="buttons"
		@update:open="(open) => !open && emit('close', result)">
		<p class="metascrub-dialog__file">
			{{ fileName }}
		</p>

		<div v-if="loading" class="metascrub-dialog__loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('metascrub', 'Checking what this photo reveals …') }}</p>
		</div>

		<NcEmptyContent
			v-else-if="report?.clean"
			:name="t('metascrub', 'Nothing to remove')"
			:description="t('metascrub', 'This image has no hidden metadata. It is already clean.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCheckCircleOutline" />
			</template>
		</NcEmptyContent>

		<template v-else-if="report">
			<NcNoteCard v-if="sensitive" type="warning">
				{{ t('metascrub', 'Anyone you share this file with can see where it was taken or which device took it.') }}
			</NcNoteCard>

			<p>{{ t('metascrub', 'This image contains:') }}</p>
			<ul class="metascrub-dialog__findings">
				<li v-for="finding in findings" :key="finding.type" class="metascrub-dialog__finding">
					<NcIconSvgWrapper :path="finding.icon" :size="20" />
					<div>
						<strong>{{ finding.label }}</strong>
						<span v-for="detail in finding.details" :key="detail" class="metascrub-dialog__detail">
							{{ detail }}
							<a
								v-if="finding.type === 'location' && mapLink(detail)"
								:href="mapLink(detail)!"
								target="_blank"
								rel="noopener noreferrer"
								class="metascrub-dialog__map">
								{{ t('metascrub', 'Show on map') }}
								<NcIconSvgWrapper :path="mdiOpenInNew" :size="14" inline />
							</a>
						</span>
					</div>
				</li>
			</ul>

			<p class="metascrub-dialog__hint">
				{{ t('metascrub', 'The picture itself is not re-encoded, so quality is unchanged. Colour profile and orientation are kept. The original stays in the version history.') }}
			</p>
			<p v-if="report.removedBytes > 0" class="metascrub-dialog__hint">
				{{ t('metascrub', 'This will make the file {size} smaller.', { size: formatFileSize(report.removedBytes) }) }}
			</p>
		</template>

		<NcNoteCard v-if="!canEdit && report && !report.clean" type="info">
			{{ t('metascrub', 'You can view this file but not change it.') }}
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

	</NcDialog>
</template>

<style scoped lang="scss">
.metascrub-dialog__file {
	color: var(--color-text-maxcontrast);
	margin-block-end: calc(2 * var(--default-grid-baseline));
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.metascrub-dialog__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding-block: calc(6 * var(--default-grid-baseline));
	color: var(--color-text-maxcontrast);
}

.metascrub-dialog__findings {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	margin-block: calc(2 * var(--default-grid-baseline));
}

.metascrub-dialog__finding {
	display: flex;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline));

	strong {
		display: block;
	}
}

.metascrub-dialog__detail {
	display: block;
	color: var(--color-text-maxcontrast);
	word-break: break-word;
}

.metascrub-dialog__map {
	margin-inline-start: var(--default-grid-baseline);
	color: var(--color-primary-element);
	white-space: nowrap;

	&:hover,
	&:focus-visible {
		text-decoration: underline;
	}
}

.metascrub-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin-block-start: calc(2 * var(--default-grid-baseline));
}
</style>
