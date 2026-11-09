<script setup lang="ts">
import { mdiPlus } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import EndpointCard from './EndpointCard.vue'
import type { Endpoint } from './types.ts'

const endpoints = ref<Endpoint[]>(loadState<Endpoint[]>('webhooktable', 'endpoints', []))
const currentUser = loadState<string>('webhooktable', 'currentUser', '')
const hookUrl = loadState<string>('webhooktable', 'hookUrl', '')
const creating = ref(false)

function onCreated(endpoint: Endpoint) {
	endpoints.value.push(endpoint)
	creating.value = false
}

function onUpdated(endpoint: Endpoint) {
	const i = endpoints.value.findIndex((e) => e.id === endpoint.id)
	if (i !== -1) {
		endpoints.value[i] = endpoint
	}
}

function onDeleted(id: string) {
	endpoints.value = endpoints.value.filter((e) => e.id !== id)
}
</script>

<template>
	<NcSettingsSection
		name="Webhook-Tabelle"
		description="Formular-Einsendungen (z. B. von Forminator) per Webhook empfangen und fortlaufend in eine Excel- oder CSV-Datei schreiben. Diese Einstellungen gelten gemeinsam für alle Administratoren.">
		<div :class="$style.list">
			<EndpointCard
				v-for="endpoint in endpoints"
				:key="endpoint.id"
				:endpoint="endpoint"
				:current-user="currentUser"
				:hook-url="hookUrl"
				@updated="onUpdated"
				@deleted="onDeleted" />

			<EndpointCard
				v-if="creating"
				:endpoint="null"
				:current-user="currentUser"
				:hook-url="hookUrl"
				@created="onCreated"
				@cancel="creating = false" />

			<NcEmptyContent
				v-if="endpoints.length === 0 && !creating"
				name="Noch keine Webhooks"
				description="Lege einen Webhook an und trage die angezeigte Adresse in deinem Formular-Plugin ein." />

			<div v-if="!creating">
				<NcButton variant="primary" @click="creating = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					Neuen Webhook anlegen
				</NcButton>
			</div>
		</div>
	</NcSettingsSection>
</template>

<style module>
.list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	max-width: 900px;
}
</style>
