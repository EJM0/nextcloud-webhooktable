import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import type { Endpoint, EndpointInput } from './types.ts'

const base = () => generateUrl('/apps/webhooktable/settings/endpoints')

export async function listEndpoints(): Promise<Endpoint[]> {
	return (await axios.get(base())).data
}

export async function createEndpoint(input: EndpointInput): Promise<Endpoint> {
	return (await axios.post(base(), input)).data
}

export async function updateEndpoint(id: string, input: EndpointInput): Promise<Endpoint> {
	return (await axios.put(`${base()}/${id}`, input)).data
}

export async function deleteEndpoint(id: string): Promise<void> {
	await axios.delete(`${base()}/${id}`)
}

export async function regenerateToken(id: string): Promise<Endpoint> {
	return (await axios.post(`${base()}/${id}/token`)).data
}

export async function writeTestRow(id: string): Promise<Endpoint> {
	return (await axios.post(`${base()}/${id}/test`)).data
}

/**
 * Lesbare Fehlermeldung aus einer Axios-Antwort holen
 *
 * @param error Fehlerobjekt
 */
export function errorMessage(error: unknown): string {
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	const e = error as any
	return e?.response?.data?.message ?? e?.message ?? 'Unbekannter Fehler'
}
