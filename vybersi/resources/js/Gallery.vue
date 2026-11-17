<script setup>
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({ gallery: { type: Object, required: true } })
const proof = props.gallery.mode === 'proof'
const draftKey = 'vybersi:' + location.pathname
let initial = {}
try { initial = JSON.parse(localStorage.getItem(draftKey)) || {} } catch {}

const knownIds = new Set(props.gallery.photos.map(photo => photo.id))
const favorites = ref((initial.favorites || []).filter(id => knownIds.has(id)))
const notes = ref(initial.notes || {})
const name = ref(initial.name || '')
const sent = ref(Boolean(initial.sent))
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
    watch([favorites, notes, name, sent, submissionKey], () => {
        try {
            localStorage.setItem(draftKey, JSON.stringify({
                favorites: favorites.value, notes: notes.value, name: name.value,
                sent: sent.value, submissionKey: submissionKey.value,
            }))
        } catch {}
    }, { deep: true })
}

function toggleFavorite(photo) {
    if (sent.value) return
    const index = favorites.value.indexOf(photo.id)
    if (index >= 0) {
        favorites.value.splice(index, 1)
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
                items: favorites.value.map(id => ({ id, note: notes.value[id] || null })),
            }),
        })
        if (!response.ok) {
            const data = await response.json().catch(() => ({}))
            throw new Error(response.status === 422 ? (data.message || 'Zkontrolujte výběr a jméno.') : 'Výběr se nepodařilo odeslat. Zkuste to znovu.')
        }
        sent.value = true
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
            </div>
            <a v-if="!proof && gallery.photos.length" class="button primary" :href="gallery.zip">Stáhnout vše v ZIP</a>
        </div>

        <div v-if="proof && gallery.photos.length" class="toolbar">
            <div class="flex flex-wrap items-center gap-2">
                <button class="button" :aria-pressed="!favoritesOnly" @click="favoritesOnly = false">Všechny <span class="count">{{ gallery.photos.length }}</span></button>
                <button class="button" :aria-pressed="favoritesOnly" @click="favoritesOnly = true">Oblíbené <span class="count">{{ favorites.length }}</span></button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button class="button" :disabled="compare.length < 2"
                    @click="open(gallery.photos.filter(photo => compare.includes(photo.id)))">Porovnat <span class="count">{{ compare.length }}</span></button>
                <button v-if="!sent" class="button primary" :disabled="!favorites.length"
                    @click="error = ''; submitDialog.showModal()">Odeslat výběr</button>
            </div>
        </div>

        <p class="sr-only" aria-live="polite">{{ live }}</p>
        <p v-if="!displayed.length" class="empty">{{ favoritesOnly ? 'Zatím nemáte žádné oblíbené snímky.' : 'V galerii zatím nejsou žádné fotografie.' }}</p>

        <ul v-else class="photo-grid">
            <li v-for="photo in displayed" :key="photo.id" class="photo-card">
                <button class="image-button" :aria-label="'Otevřít ' + photo.name" @click="open([photo])">
                    <img :src="photo.thumb" alt="" loading="lazy" decoding="async">
                </button>
                <div class="card-body">
                    <span class="filename" :title="photo.name">{{ photo.name }}</span>
                    <div v-if="proof" class="flex gap-2">
                        <button class="button small" :disabled="sent" :aria-pressed="favorites.includes(photo.id)"
                            @click="toggleFavorite(photo)">{{ favorites.includes(photo.id) ? '♥ Oblíbená' : '♡ Oblíbit' }}</button>
                        <button class="button small" :aria-pressed="compare.includes(photo.id)"
                            @click="toggleCompare(photo)">{{ compare.includes(photo.id) ? '✓ Porovnání' : 'Porovnat' }}</button>
                    </div>
                    <a v-else class="button small" :href="photo.download">Stáhnout</a>
                </div>
            </li>
        </ul>
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
                        <button v-if="proof" class="button small" :disabled="sent"
                            :aria-pressed="favorites.includes(photo.id)" @click="toggleFavorite(photo)">
                            {{ favorites.includes(photo.id) ? '♥ Oblíbená' : '♡ Oblíbit' }}
                        </button>
                        <a v-else class="button small" :href="photo.download">Stáhnout</a>
                    </div>
                    <label v-if="proof" class="field-label mt-3">
                        Poznámka k fotografii
                        <textarea v-model="notes[photo.id]" class="field" :readonly="sent" rows="2"
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
            <p v-if="error" class="error mt-3" role="alert">{{ error }}</p>
            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" class="button" :disabled="sending" @click="submitDialog.close()">Zpět</button>
                <button class="button primary" :disabled="!name || sending">{{ sending ? 'Odesílám…' : 'Odeslat fotografovi' }}</button>
            </div>
        </form>
    </dialog>
</template>
