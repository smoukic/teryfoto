<script setup>
import { computed } from 'vue'
import { selectionPrice } from './selection-price'

const props = defineProps({ pricing: Object, units: Number })
const order = defineModel({ required: true })
const format = new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', maximumFractionDigits: 0 })
const shipping = computed(() => props.pricing.shipping.find(item => item.id === order.value.shipping))
const services = computed(() => props.pricing.services.filter(item => order.value.services.includes(item.id)))
const total = computed(() => selectionPrice(props.pricing, props.units, services.value, shipping.value))
</script>

<template>
    <div class="mt-4 grid gap-4">
        <fieldset v-if="pricing.services.length">
            <legend class="field-label">Doplňkové služby</legend>
            <label v-for="item in pricing.services" :key="item.id" class="check-row">
                <input v-model="order.services" type="checkbox" :value="item.id">
                <span>{{ item.category ? item.category + ' · ' : '' }}{{ item.name }} · {{ format.format(item.price) }}</span>
            </label>
        </fieldset>
        <label v-if="pricing.shipping.length" class="field-label">
            Způsob doručení
            <select v-model="order.shipping" class="field" required>
                <option :value="null" disabled>Vyberte doručení</option>
                <option v-for="item in pricing.shipping" :key="item.id" :value="item.id">
                    {{ item.name }} · {{ format.format(item.price) }}
                </option>
            </select>
        </label>
        <label v-if="shipping?.address" class="field-label">
            Doručovací adresa
            <textarea v-model.trim="order.address" class="field" rows="3" maxlength="500" autocomplete="street-address" required></textarea>
        </label>
        <dl class="price-breakdown" aria-live="polite">
            <div><dt>Balíček · {{ pricing.included }} ks v ceně</dt><dd>{{ format.format(pricing.price) }}</dd></div>
            <div v-if="total.extraUnits"><dt>Navíc · {{ total.extraUnits }} × {{ format.format(total.unitPrice) }}</dt><dd>{{ format.format(total.extraUnits * total.unitPrice) }}</dd></div>
            <div v-if="pricing.travel"><dt>Doprava na focení</dt><dd>{{ format.format(pricing.travel) }}</dd></div>
            <div v-if="pricing.shoot"><dt>Focení</dt><dd>{{ format.format(pricing.shoot) }}</dd></div>
            <div v-for="item in services" :key="item.id"><dt>{{ item.name }}</dt><dd>{{ format.format(item.price) }}</dd></div>
            <div v-if="shipping"><dt>{{ shipping.name }}</dt><dd>{{ format.format(shipping.price) }}</dd></div>
            <div v-if="pricing.paid"><dt>Již zaplaceno</dt><dd>−{{ format.format(pricing.paid) }}</dd></div>
            <div v-if="pricing.voucher"><dt>Dárkový poukaz</dt><dd>−{{ format.format(pricing.voucher) }}</dd></div>
            <div class="price-total"><dt>K doplacení</dt><dd>{{ format.format(total.due) }}</dd></div>
        </dl>
    </div>
</template>
