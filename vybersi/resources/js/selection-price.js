export function selectionPrice(pricing, units, services, shipping) {
    const extraUnits = Math.max(0, units - pricing.included)
    const unitPrice = pricing.tiers.filter(tier => units >= tier.from).at(-1)?.price ?? pricing.extra
    const subtotal = pricing.price + extraUnits * unitPrice + pricing.travel + pricing.shoot
        + services.reduce((sum, item) => sum + item.price, 0) + (shipping?.price ?? 0)

    return { extraUnits, unitPrice, due: Math.max(0, subtotal - pricing.paid - pricing.voucher) }
}
