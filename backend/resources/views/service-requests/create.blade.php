@extends('layouts.app')

@section('title', 'Nouvelle demande')

@section('content')
    <section
        x-data="serviceRequestForm(@js([
            'services' => $services,
            'fees' => $fees,
            'maxQuantity' => $maxQuantity,
            'storeUrl' => route('api.service-requests.store'),
        ]))"
        class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"
    >
        <h1 class="text-2xl font-semibold text-slate-900">Nouvelle demande</h1>
        <p class="mt-1 text-sm text-slate-500">Choisissez l'acte souhaité et le nombre d'exemplaires.</p>

        <form class="mt-8 space-y-6" novalidate @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-[1fr_9rem]">
                <div>
                    <label for="service_code" class="block text-sm font-medium text-slate-700">Service</label>
                    <select
                        id="service_code"
                        x-model="serviceCode"
                        @change="clearError('service_code')"
                        :aria-invalid="hasError('service_code')"
                        aria-describedby="service_code-error"
                        class="mt-1 block w-full rounded-lg border-slate-300 px-3 py-2.5 ring-1 ring-slate-300 focus:ring-2 focus:ring-emerald-600 focus:outline-none"
                        :class="hasError('service_code') && 'ring-red-500'"
                    >
                        <option value="">Choisissez un service</option>
                        <template x-for="service in services" :key="service.code">
                            <option :value="service.code" x-text="`${service.title} (${money(service.price)})`"></option>
                        </template>
                    </select>
                    <p id="service_code-error" class="mt-1 text-sm text-red-600" x-show="hasError('service_code')" x-text="errors.service_code" x-cloak></p>
                </div>

                <div>
                    <label for="quantity" class="block text-sm font-medium text-slate-700">Quantité</label>
                    <input
                        id="quantity"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        :max="maxQuantity"
                        step="1"
                        x-model.number="quantity"
                        @input="clearError('quantity')"
                        :aria-invalid="hasError('quantity')"
                        aria-describedby="quantity-error"
                        class="mt-1 block w-full rounded-lg px-3 py-2.5 ring-1 ring-slate-300 focus:ring-2 focus:ring-emerald-600 focus:outline-none"
                        :class="hasError('quantity') && 'ring-red-500'"
                    >
                    <p id="quantity-error" class="mt-1 text-sm text-red-600" x-show="hasError('quantity')" x-text="errors.quantity" x-cloak></p>
                </div>
            </div>

            <dl class="space-y-2 rounded-xl bg-slate-50 p-4 text-sm" aria-live="polite">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-600" x-text="selectedService ? `${selectedService.title} : ${quantity} × ${money(selectedService.price)}` : 'Service'"></dt>
                    <dd class="font-medium tabular-nums" x-text="selectedService ? money(subtotal) : '-'"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-600">Frais de dossier</dt>
                    <dd class="font-medium tabular-nums" x-text="money(fees)"></dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-slate-200 pt-2 text-base">
                    <dt class="font-semibold text-slate-900">Total</dt>
                    <dd class="font-semibold text-slate-900 tabular-nums" x-text="selectedService ? money(total) : '-'"></dd>
                </div>
            </dl>

            <p class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" role="alert" x-show="generalError" x-text="generalError" x-cloak></p>

            <button
                type="submit"
                class="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white transition hover:bg-emerald-800 focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="submitting"
            >
                <span x-show="!submitting">Demander</span>
                <span x-show="submitting" x-cloak>Enregistrement…</span>
            </button>
        </form>
    </section>
@endsection
