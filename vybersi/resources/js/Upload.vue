<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ endpoint: String, back: String })
const entries = ref([])
const busy = ref(false)
const dragging = ref(false)
const done = computed(() => entries.value.filter(entry => entry.state === 'saved').length)
const pending = computed(() => entries.value.some(entry => entry.state === 'ready' || entry.state === 'failed'))

function add(files) {
    for (const file of files) {
        entries.value.push({ file, state: 'ready', message: '' })
    }
}

async function upload() {
    busy.value = true
    const csrf = document.querySelector('meta[name="csrf-token"]').content
    const batch = entries.value.filter(entry => entry.state === 'ready' || entry.state === 'failed')
    for (const entry of batch) {
        entry.state = 'sending'
        entry.message = 'Nahrávám…'
        const body = new FormData()
        body.append('photo', entry.file)
        try {
            const response = await fetch(props.endpoint, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body,
            })
            if (!response.ok) {
                const result = await response.json().catch(() => ({}))
                throw new Error(result.errors?.photo?.[0] || result.errors?.photos?.[0] || 'Soubor se nepodařilo nahrát.')
            }
            entry.state = 'saved'
            entry.message = 'Nahráno'
        } catch (error) {
            entry.state = 'failed'
            entry.message = error.message
        }
    }
    busy.value = false
}
</script>

<template>
    <label class="dropzone mt-8" :class="{ dragging }" @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false" @drop.prevent="dragging = false; add($event.dataTransfer.files)">
        <span class="text-3xl" aria-hidden="true">↑</span>
        <strong>Přetáhněte fotografie nebo vyberte soubory</strong>
        <span class="muted">JPEG, PNG nebo WebP · max. 28 MB a 50 Mpx na fotografii</span>
        <input class="sr-only" type="file" multiple accept="image/jpeg,image/png,image/webp"
            @change="add($event.target.files); $event.target.value = ''">
    </label>
    <div v-if="entries.length" class="panel mt-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p role="status">Nahráno {{ done }} z {{ entries.length }}</p>
            <button v-if="pending || busy" class="button primary" type="button" :disabled="busy" @click="upload">
                {{ busy ? 'Nahrávám…' : 'Nahrát připravené fotografie' }}
            </button>
            <a v-else class="button primary" :href="back">Hotovo</a>
        </div>
        <ul class="mt-4 divide-y divide-stone-200">
            <li v-for="(entry, index) in entries" :key="index" class="flex flex-wrap items-center justify-between gap-2 py-3">
                <span class="break-all">{{ entry.file.name }}</span>
                <div class="flex items-center gap-3">
                    <span :class="entry.state === 'failed' ? 'error' : 'muted'">{{ entry.message || 'Připraveno' }}</span>
                    <button v-if="entry.state === 'ready' || entry.state === 'failed'" class="button small"
                        type="button" :disabled="busy" @click="entries.splice(index, 1)">Odebrat</button>
                </div>
            </li>
        </ul>
    </div>
</template>
