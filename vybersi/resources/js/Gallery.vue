<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import SelectionPricing from './SelectionPricing.vue'
import { selectionPrice } from './selection-price'

const props = defineProps({ gallery: { type: Object, required: true } })
const proof = props.gallery.mode === 'proof'
const draftKey = 'vybersi:' + location.pathname
let initial = {}
try { initial = JSON.parse(localStorage.getItem(draftKey)) || {} } catch {}

const knownIds = new Set(props.gallery.photos.map(photo => photo.id))
const favorites = ref((initial.favorites || []).filter(id => knownIds.has(id)))
const notes = ref(initial.notes || {})
const name = ref(initial.name || '')
const sent = ref(Boolean(props.gallery.receipt || initial.sent))
const receipt = ref(props.gallery.receipt)
const selectionLocked = computed(() => sent.value || props.gallery.selectionClosed)
const photosHidden = computed(() => props.gallery.photosHidden || (sent.value && props.gallery.hideAfter))
const quantities = ref(initial.quantities || {})
const order = ref({ services: [], shipping: null, address: '' })
const terms = ref(false)
const consent = ref(false)
const money = new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', maximumFractionDigits: 0 })
const quantityFor = id => props.gallery.pricing?.quantities ? Math.min(99, Math.max(1, Math.trunc(Number(quantities.value[id]) || 1))) : 1
const units = computed(() => favorites.value.reduce((sum, id) => sum + quantityFor(id), 0))
const estimate = computed(() => {
    const pricing = props.gallery.pricing
    if (!pricing || !favorites.value.length) return null
    const services = pricing.services.filter(item => order.value.services.includes(item.id))
    const shipping = pricing.shipping.find(item => item.id === order.value.shipping)
    return selectionPrice(pricing, units.value, services, shipping).due
})
const page = ref(1)
const pageSize = 60
const compare = ref([])
const favoritesOnly = ref(false)
const viewer = ref(null)
const submitDialog = ref(null)
const visible = ref([])
const zoom = ref(1)
const sending = ref(false)
const error = ref('')
const live = ref('')
const displayed = computed(() => favoritesOnly.value
    ? props.gallery.photos.filter(photo => favorites.value.includes(photo.id))
    : props.gallery.photos)
const pages = computed(() => Math.ceil(displayed.value.length / pageSize))
const pagePhotos = computed(() => displayed.value.slice((page.value - 1) * pageSize, page.value * pageSize))
watch(favoritesOnly, () => { page.value = 1 })
watch(pages, count => { page.value = Math.min(page.value, Math.max(1, count)) })
const current = computed(() => visible.value.length === 1 ? visible.value[0] : null)

function newKey() {
    const bytes = crypto.getRandomValues(new Uint8Array(16))
    bytes[6] = (bytes[6] & 15) | 64
    bytes[8] = (bytes[8] & 63) | 128
    const hex = Array.from(bytes, number => number.toString(16).padStart(2, '0')).join('')
    return hex.replace(/^(........)(....)(....)(....)(............)$/, '$1-$2-$3-$4-$5')
}

const submissionKey = ref(initial.submissionKey || newKey())
if (proof) {
    watch([favorites, notes, name, sent, submissionKey, quantities], () => {
        try {
            localStorage.setItem(draftKey, JSON.stringify({
                favorites: favorites.value, notes: notes.value, name: name.value,
                sent: sent.value, submissionKey: submissionKey.value, quantities: quantities.value,
            }))
        } catch {}
    }, { deep: true })
}

function toggleFavorite(photo) {
    if (selectionLocked.value) return
    const index = favorites.value.indexOf(photo.id)
    if (index >= 0) {
        favorites.value.splice(index, 1)
    } else if (favorites.value.length >= props.gallery.limit) {
        live.value = "Dosáhli jste limitu výběru (" + props.gallery.limit + ")."
    } else {
        favorites.value.push(photo.id)
    }
}

function toggleCompare(photo) {
    if (compare.value.includes(photo.id)) {
        compare.value = compare.value.filter(id => id !== photo.id)
    } else if (compare.value.length < 3) {
        compare.value.push(photo.id)
    } else {
        live.value = 'Lze porovnat nejvýše tři fotografie.'
    }
}

async function open(photos) {
    visible.value = photos
    zoom.value = 1
    await nextTick()
    if (!viewer.value.open) viewer.value.showModal()
}

function step(offset) {
    if (!current.value) return
    const photos = displayed.value.some(photo => photo.id === current.value.id) ? displayed.value : props.gallery.photos
    const index = photos.findIndex(photo => photo.id === current.value.id)
    visible.value = [photos[(index + offset + photos.length) % photos.length]]
    zoom.value = 1
}

async function send() {
    if (!favorites.value.length || sending.value) return
    sending.value = true
    error.value = ''
    try {
        const response = await fetch(props.gallery.submit, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                name: name.value,
                submission_key: submissionKey.value,
                items: favorites.value.map(id => ({ id, quantity: quantityFor(id), note: props.gallery.notesAllowed ? notes.value[id] || null : null })),
                revision: props.gallery.pricing?.revision,
                services: order.value.services,
                shipping: order.value.shipping,
                address: order.value.address,
                terms_accepted: terms.value,
                publication_consent: consent.value,
            }),
        })
        if (!response.ok) {
            const data = await response.json().catch(() => ({}))
            throw new Error(response.status === 422 ? (data.message || 'Zkontrolujte výběr a jméno.') : 'Výběr se nepodařilo odeslat. Zkuste to znovu.')
        }
        receipt.value = (await response.json()).receipt
        sent.value = true
        order.value.address = ''
        submitDialog.value.close()
        live.value = 'Výběr byl odeslán fotografovi.'
    } catch (failure) {
        error.value = failure.message
    } finally {
        sending.value = false
    }
}
</script>

<template>
    <main class="frame pb-20">
        <div class="hero flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="kicker">{{ proof ? 'Výběr fotografií' : 'Hotové fotografie' }}</p>
                <h1>{{ gallery.title }}</h1>
                <p v-if="gallery.description" class="intro">{{ gallery.description }}</p>
                <p v-if="sent" class="success mt-5">✓ Výběr byl odeslán fotografovi.</p>
                <p v-else-if="gallery.selectionClosed" class="success mt-5">Finální výběr už byl odeslán.</p>
                <p v-if="receipt?.thanks" class="intro">{{ receipt.thanks }}</p>
                <div v-if="receipt?.due != null" class="panel mt-4">
                    <strong>K doplacení {{ money.format(receipt.due) }}</strong>
                    <p v-if="receipt.payment" class="intro">{{ receipt.payment }}</p>
                </div>
            </div>
            <a v-if="!proof && gallery.zip && gallery.photos.length" class="button primary" :href="gallery.zip">Stáhnout vše v ZIP</a>
        </div>

        <div v-if="!photosHidden && proof && gallery.photos.length" class="toolbar">
            <div class="flex flex-wrap items-center gap-2">
                <button class="button" :aria-pressed="!favoritesOnly" @click="favoritesOnly = false">Všechny <span class="count">{{ gallery.photos.length }}</span></button>
                <button class="button" :aria-pressed="favoritesOnly" @click="favoritesOnly = true">Oblíbené <span class="count">{{ favorites.length }}</span></button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span v-if="estimate != null && !selectionLocked" class="muted text-sm" aria-live="polite">
                    {{ units }} ks · doplatek {{ money.format(estimate) }}
                </span>
                <button class="button" :disabled="compare.length < 2"
                    @click="open(gallery.photos.filter(photo => compare.includes(photo.id)))">Porovnat <span class="count">{{ compare.length }}</span></button>
                <button v-if="!selectionLocked" class="button primary" :disabled="!favorites.length"
                    @click="error = ''; submitDialog.showModal()">Odeslat výběr</button>
            </div>
        </div>

        <p class="sr-only" aria-live="polite">{{ live }}</p>
        <p v-if="photosHidden" class="empty">Fotografie byly po odeslání výběru skryty.</p>
        <p v-else-if="!displayed.length" class="empty">{{ favoritesOnly ? 'Zatím nemáte žádné oblíbené snímky.' : 'V galerii zatím nejsou žádné fotografie.' }}</p>

        <ul v-else class="photo-grid">
            <li v-for="photo in pagePhotos" :key="photo.id" class="photo-card">
                <button class="image-button" :aria-label="'Otevřít ' + photo.name" @click="open([photo])">
                    <img :src="photo.thumb" alt="" loading="lazy" decoding="async">
                </button>
                <div class="card-body">
                    <span class="filename" :title="photo.name">{{ photo.name }}</span>
                    <div v-if="proof" class="flex gap-2">
                        <button class="button small" :disabled="selectionLocked" :aria-pressed="favorites.includes(photo.id)"
                            @click="toggleFavorite(photo)">{{ favorites.includes(photo.id) ? '♥ Oblíbená' : '♡ Oblíbit' }}</button>
                        <button class="button small" :aria-pressed="compare.includes(photo.id)"
                            @click="toggleCompare(photo)">{{ compare.includes(photo.id) ? '✓ Porovnání' : 'Porovnat' }}</button>
                    </div>
                    <a v-else-if="photo.download" class="button small" :href="photo.download">Stáhnout</a>
                    <label v-if="gallery.pricing?.quantities && favorites.includes(photo.id)" class="field-label">
                        Počet kusů <input class="field quantity" type="number" min="1" max="99"
                            :value="quantityFor(photo.id)" :disabled="selectionLocked"
                            @change="quantities[photo.id] = $event.target.value; $event.target.value = quantityFor(photo.id)">
                    </label>
                </div>
            </li>
        </ul>
        <nav v-if="!photosHidden && pages > 1" class="mt-5 flex flex-wrap items-center justify-center gap-3" aria-label="Stránky galerie">
            <button class="button" :disabled="page === 1" @click="page--">Předchozí</button>
            <span aria-live="polite">{{ page }} / {{ pages }}</span>
            <button class="button" :disabled="page === pages" @click="page++">Další</button>
        </nav>
    </main>

    <dialog ref="viewer" class="viewer" aria-label="Náhled fotografií"
        @keydown.left="!$event.target.matches('input,textarea') && step(-1)"
        @keydown.right="!$event.target.matches('input,textarea') && step(1)"
        @click.self="viewer.close()">
        <div class="viewer-inner">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="current">
                        <button class="button" aria-label="Předchozí fotografie" @click="step(-1)">←</button>
                        <button class="button" aria-label="Další fotografie" @click="step(1)">→</button>
                    </template>
                    <label class="zoom flex items-center gap-2">
                        Přiblížení <input v-model.number="zoom" type="range" min="1" max="3" step="0.25">
                    </label>
                </div>
                <button class="button" autofocus @click="viewer.close()">Zavřít ✕</button>
            </div>
            <div class="viewer-grid" :style="{ '--columns': visible.length }">
                <article v-for="photo in visible" :key="photo.id">
                    <div class="pan" tabindex="0" :aria-label="'Náhled ' + photo.name">
                        <img :src="photo.preview" :alt="photo.name"
                            :style="{ width: zoom * 100 + '%', height: zoom * 100 + '%' }">
                    </div>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="break-all">{{ photo.name }}</p>
                        <button v-if="proof" class="button small" :disabled="selectionLocked"
                            :aria-pressed="favorites.includes(photo.id)" @click="toggleFavorite(photo)">
                            {{ favorites.includes(photo.id) ? '♥ Oblíbená' : '♡ Oblíbit' }}
                        </button>
                        <a v-else-if="photo.download" class="button small" :href="photo.download">Stáhnout</a>
                    </div>
                    <label v-if="proof && gallery.notesAllowed" class="field-label mt-3">
                        Poznámka k fotografii
                        <textarea v-model="notes[photo.id]" class="field" :readonly="selectionLocked" rows="2"
                            maxlength="1500" placeholder="Odešle se, když je fotografie v oblíbených."></textarea>
                    </label>
                </article>
            </div>
        </div>
    </dialog>

    <dialog v-if="proof" ref="submitDialog" class="submit-dialog" aria-labelledby="submit-title"
        @cancel="sending && $event.preventDefault()" @click.self="!sending && submitDialog.close()">
        <form class="panel" @submit.prevent="send">
            <h2 id="submit-title">Odeslat výběr</h2>
            <p class="muted my-3">Fotograf obdrží výběr ({{ favorites.length }}) i poznámky k vybraným snímkům.</p>
            <label class="field-label">
                Vaše jméno <input v-model.trim="name" class="field" autocomplete="name" required maxlength="120" :disabled="sending">
            </label>
            <SelectionPricing v-if="gallery.pricing" v-model="order" :pricing="gallery.pricing" :units="units" />
            <div v-if="gallery.terms" class="mt-4">
                <details class="terms">
                    <summary>Obchodní podmínky</summary>
                    <p class="mt-2 whitespace-pre-wrap break-words">{{ gallery.terms }}</p>
                </details>
                <label class="check-row"><input v-model="terms" type="checkbox" required> Souhlasím s obchodními podmínkami</label>
            </div>
            <label v-if="gallery.askConsent" class="check-row">
                <input v-model="consent" type="checkbox"> Souhlasím se zveřejněním vybraných fotografií
            </label>
            <p v-if="error" class="error mt-3" role="alert">{{ error }}</p>
            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" class="button" :disabled="sending" @click="submitDialog.close()">Zpět</button>
                <button class="button primary" :disabled="!name || sending">{{ sending ? 'Odesílám…' : 'Odeslat fotografovi' }}</button>
            </div>
        </form>
    </dialog>
</template>
