<script setup lang="ts">
import { mdiCheck, mdiContentCopy, mdiDelete, mdiEye, mdiEyeOff, mdiFolderOutline, mdiKeyChange, mdiTableArrowDown } from '@mdi/js'
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { computed, reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import * as api from './api.ts'
import type { Endpoint, EndpointInput } from './types.ts'

const props = defineProps<{
	endpoint: Endpoint | null
	currentUser: string
	hookUrl: string
}>()

const emit = defineEmits<{
	created: [endpoint: Endpoint]
	updated: [endpoint: Endpoint]
	deleted: [id: string]
	cancel: []
}>()

const isNew = computed(() => props.endpoint === null)
const editing = ref(isNew.value)
const busy = ref(false)
const showToken = ref(false)

function toForm(e: Endpoint | null): EndpointInput {
	return {
		name: e?.name ?? 'Bestellungen Vereinskleidung',
		enabled: e?.enabled ?? true,
		owner: e?.owner ?? props.currentUser,
		folder: e?.folder ?? '/',
		filename: e?.filename ?? 'Bestellungen.xlsx',
		format: e?.format ?? 'xlsx',
		timestamp: e?.timestamp ?? true,
		mapping: e?.mapping ?? '',
		onlyMapped: e?.onlyMapped ?? false,
	}
}

const form = reactive<EndpointInput>(toForm(props.endpoint))
watch(() => props.endpoint, (e) => {
	if (!editing.value) {
		Object.assign(form, toForm(e))
	}
})

// Dateiendung beim Formatwechsel anpassen
watch(() => form.format, (format) => {
	form.filename = form.filename.replace(/\.(xlsx|csv)$/i, '') + '.' + format
})

const url = computed(() => props.endpoint ? props.hookUrl.replace('__ID__', props.endpoint.id) : '')
const urlWithToken = computed(() => props.endpoint ? `${url.value}?token=${props.endpoint.token}` : '')
const targetPath = computed(() => {
	const folder = form.folder === '/' ? '' : form.folder
	return `${folder}/${form.filename}`
})
const canPick = computed(() => form.owner === props.currentUser)

function formatDate(iso: string | null): string {
	if (!iso) {
		return ''
	}
	return new Date(iso).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' })
}

async function copy(text: string, what: string) {
	try {
		await navigator.clipboard.writeText(text)
		showSuccess(`${what} kopiert`)
	} catch {
		window.prompt(`${what} kopieren:`, text)
	}
}

async function pickFolder() {
	let currentPath = form.folder
	try {
		const picked = await getFilePickerBuilder('Speicherort wählen')
			.setMultiSelect(false)
			.addMimeTypeFilter('httpd/unix-directory')
			.allowDirectories(true)
			.startAt(form.folder)
			.setButtonFactory((nodes, path) => {
				currentPath = path
				const label = nodes.length === 1 ? `„${nodes[0]!.basename}“ wählen` : 'Diesen Ordner wählen'
				return [{ label, variant: 'primary', callback: () => {} }]
			})
			.build()
			.pick()
		form.folder = (picked || currentPath || '/').replace(/\/+$/, '') || '/'
	} catch {
		// Dialog geschlossen
	}
}

async function save() {
	busy.value = true
	try {
		if (props.endpoint === null) {
			const created = await api.createEndpoint({ ...form })
			showSuccess('Webhook angelegt')
			emit('created', created)
		} else {
			const updated = await api.updateEndpoint(props.endpoint.id, { ...form })
			showSuccess('Gespeichert')
			editing.value = false
			emit('updated', updated)
		}
	} catch (e) {
		showError(api.errorMessage(e))
	} finally {
		busy.value = false
	}
}

function cancel() {
	if (isNew.value) {
		emit('cancel')
		return
	}
	Object.assign(form, toForm(props.endpoint))
	editing.value = false
}

async function test() {
	if (!props.endpoint) {
		return
	}
	busy.value = true
	try {
		emit('updated', await api.writeTestRow(props.endpoint.id))
		showSuccess(`Testzeile in ${targetPath.value} geschrieben`)
	} catch (e) {
		showError(api.errorMessage(e))
	} finally {
		busy.value = false
	}
}

async function newToken() {
	if (!props.endpoint || !window.confirm('Neuen Schlüssel erzeugen? Die bisherige Webhook-Adresse funktioniert danach nicht mehr und muss im Formular ersetzt werden.')) {
		return
	}
	busy.value = true
	try {
		emit('updated', await api.regenerateToken(props.endpoint.id))
		showToken.value = true
		showSuccess('Neuer Schlüssel erzeugt')
	} catch (e) {
		showError(api.errorMessage(e))
	} finally {
		busy.value = false
	}
}

async function remove() {
	if (!props.endpoint || !window.confirm(`Webhook „${props.endpoint.name}“ löschen? Die Tabelle selbst bleibt erhalten.`)) {
		return
	}
	busy.value = true
	try {
		await api.deleteEndpoint(props.endpoint.id)
		emit('deleted', props.endpoint.id)
	} catch (e) {
		showError(api.errorMessage(e))
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<section :class="[$style.card, { [$style.disabled]: endpoint && !endpoint.enabled }]">
		<!-- Kopf -->
		<header v-if="endpoint" :class="$style.head">
			<div>
				<h3 :class="$style.title">
					{{ endpoint.name }}
					<span v-if="!endpoint.enabled" :class="$style.badge">deaktiviert</span>
				</h3>
				<p :class="$style.meta">
					{{ endpoint.count }} {{ endpoint.count === 1 ? 'Einsendung' : 'Einsendungen' }}
					<template v-if="endpoint.lastReceived">
						· zuletzt {{ formatDate(endpoint.lastReceived) }}
					</template>
					· Ziel: <strong>{{ endpoint.owner }}</strong>{{ endpoint.folder === '/' ? '' : endpoint.folder }}/{{ endpoint.filename }}
				</p>
			</div>
		</header>
		<h3 v-else :class="$style.title">
			Neuer Webhook
		</h3>

		<NcNoteCard v-if="endpoint?.lastError" type="error">
			Letzter Fehler ({{ formatDate(endpoint.lastErrorAt) }}): {{ endpoint.lastError }}
		</NcNoteCard>

		<!-- Adresse und Schlüssel -->
		<div v-if="endpoint" :class="$style.grid">
			<div :class="$style.row">
				<NcTextField
					:model-value="url"
					:class="$style.grow"
					label="Webhook-Adresse (POST)"
					readonly />
				<NcButton
					:class="$style.pick"
					aria-label="Adresse kopieren"
					title="Adresse kopieren"
					@click="copy(url, 'Adresse')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiContentCopy" />
					</template>
				</NcButton>
			</div>

			<div :class="$style.row">
				<NcTextField
					:model-value="showToken ? endpoint.token : '•'.repeat(24)"
					:class="$style.grow"
					label="Geheimer Schlüssel"
					helper-text="Als Header „X-Webhook-Token“, als „Authorization: Bearer …“ oder als ?token= in der Adresse senden."
					readonly />
				<NcButton
					:class="$style.pick"
					:aria-label="showToken ? 'Schlüssel verbergen' : 'Schlüssel anzeigen'"
					:title="showToken ? 'Schlüssel verbergen' : 'Schlüssel anzeigen'"
					@click="showToken = !showToken">
					<template #icon>
						<NcIconSvgWrapper :path="showToken ? mdiEyeOff : mdiEye" />
					</template>
				</NcButton>
				<NcButton
					:class="$style.pick"
					aria-label="Schlüssel kopieren"
					title="Schlüssel kopieren"
					@click="copy(endpoint.token, 'Schlüssel')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiContentCopy" />
					</template>
				</NcButton>
			</div>

			<div :class="$style.actions">
				<NcButton variant="primary" :disabled="busy" @click="copy(urlWithToken, 'Adresse mit Schlüssel')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiContentCopy" />
					</template>
					Adresse mit Schlüssel kopieren (für Forminator)
				</NcButton>
				<NcButton :disabled="busy" @click="test">
					<template #icon>
						<NcIconSvgWrapper :path="mdiTableArrowDown" />
					</template>
					Testzeile schreiben
				</NcButton>
				<NcButton :disabled="busy" @click="newToken">
					<template #icon>
						<NcIconSvgWrapper :path="mdiKeyChange" />
					</template>
					Neuer Schlüssel
				</NcButton>
				<NcButton v-if="!editing"
					variant="tertiary"
					:disabled="busy"
					@click="editing = true">
					Bearbeiten
				</NcButton>
			</div>
		</div>

		<!-- Einstellungen -->
		<form v-if="editing" :class="$style.grid" @submit.prevent="save">
			<NcTextField v-model="form.name" label="Name" required />

			<div :class="$style.row">
				<NcTextField
					v-model="form.owner"
					:class="$style.owner"
					label="Speicherort-Benutzer"
					helper-text="Benutzername, in dessen Dateien gespeichert wird" />
				<NcTextField
					v-model="form.folder"
					:class="$style.grow"
					label="Ordner"
					:helper-text="canPick ? 'Auch geteilte Ordner und Team-Ordner sind möglich' : 'Auswahl nur für eigene Dateien möglich, bitte Pfad eintippen'" />
				<NcButton :disabled="!canPick" :class="$style.pick" @click.prevent="pickFolder">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderOutline" />
					</template>
					Ordner wählen
				</NcButton>
			</div>

			<div :class="$style.row">
				<NcTextField
					v-model="form.filename"
					:class="$style.grow"
					label="Dateiname"
					helper-text="Wird beim ersten Eingang angelegt. Existiert die Datei, werden Zeilen unten angehängt." />
				<div :class="$style.formats">
					<NcCheckboxRadioSwitch v-model="form.format"
						type="radio"
						value="xlsx"
						:name="`format-${endpoint?.id ?? 'new'}`">
						Excel (.xlsx)
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="form.format"
						type="radio"
						value="csv"
						:name="`format-${endpoint?.id ?? 'new'}`">
						CSV
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<NcCheckboxRadioSwitch v-model="form.timestamp" type="switch">
				Spalte „Eingang“ mit Datum und Uhrzeit hinzufügen
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				Webhook aktiv
			</NcCheckboxRadioSwitch>

			<NcTextArea
				v-model="form.mapping"
				label="Feldzuordnung (optional)"
				resize="vertical"
				:class="$style.mapping"
				placeholder="name-1 = Name&#10;email-1 = E-Mail&#10;select-1 = Größe&#10;consent-1 =        (leer = Feld ignorieren)"
				helper-text="Eine Zeile pro Feld: technischer Feldname = Spaltenüberschrift. Nicht zugeordnete Felder bekommen ihren technischen Namen als Spalte." />
			<NcCheckboxRadioSwitch v-model="form.onlyMapped" type="switch">
				Nur zugeordnete Felder speichern
			</NcCheckboxRadioSwitch>

			<p :class="$style.meta">
				Zieldatei: <strong>{{ form.owner }}</strong>{{ targetPath }}
			</p>

			<div :class="$style.actions">
				<NcButton type="submit" variant="primary" :disabled="busy">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCheck" />
					</template>
					{{ isNew ? 'Webhook anlegen' : 'Speichern' }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" @click.prevent="cancel">
					Abbrechen
				</NcButton>
				<NcButton
					v-if="!isNew"
					variant="error"
					:class="$style.end"
					:disabled="busy"
					@click.prevent="remove">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDelete" />
					</template>
					Löschen
				</NcButton>
			</div>
		</form>
	</section>
</template>

<style module>
.card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.disabled {
	opacity: .75;
}

.head {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}

.title {
	margin: 0;
	font-size: 1.15em;
	font-weight: bold;
	display: flex;
	align-items: center;
	gap: 8px;
}

.badge {
	font-size: .75em;
	font-weight: normal;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.meta {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.grid {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 12px;
}

.owner {
	flex: 0 1 260px;
}

.grow {
	flex: 1 1 260px;
}

.pick {
	margin-top: 6px;
}

.formats {
	display: flex;
	gap: 8px;
	margin-top: 6px;
}

.mapping :global(textarea) {
	font-family: var(--font-face-monospace, monospace);
	min-height: 110px;
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.end {
	margin-inline-start: auto;
}
</style>
