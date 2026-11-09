export interface Endpoint {
	id: string
	name: string
	token: string
	enabled: boolean
	owner: string
	folder: string
	filename: string
	format: 'xlsx' | 'csv'
	timestamp: boolean
	mapping: string
	onlyMapped: boolean
	createdBy: string
	createdAt: string
	count: number
	lastReceived: string | null
	lastError: string | null
	lastErrorAt: string | null
}

export type EndpointInput = Pick<Endpoint, 'name' | 'enabled' | 'owner' | 'folder' | 'filename' | 'format' | 'timestamp' | 'mapping' | 'onlyMapped'>
