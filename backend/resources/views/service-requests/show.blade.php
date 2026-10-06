@php
    use App\Support\Money;
@endphp

@extends('layouts.app')

@section('title', 'Demande '.$serviceRequest->code)

@section('content')
    <section
        x-data="paymentPanel(@js([
            'state' => $state,
            'operators' => $operators,
            'paymentUrl' => route('api.service-requests.payments.store', $serviceRequest),
            'statusUrl' => route('api.service-requests.show', $serviceRequest),
        ]))"
        class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Votre demande</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Conservez votre référence : elle vous permet de retrouver votre demande.
                </p>
            </div>
            <span
                class="rounded-full px-3 py-1 text-sm font-medium"
                :class="{
                    'bg-amber-100 text-amber-800': state.status === 'PENDING_PAYMENT',
                    'bg-sky-100 text-sky-800': state.status === 'PAYMENT_IN_PROGRESS',
                    'bg-emerald-100 text-emerald-800': state.status === 'PAID',
                }"
                x-text="state.status_label"
                data-testid="status"
            >{{ $serviceRequest->status->label() }}</span>
        </div>

        <div class="mt-6 rounded-xl border border-dashed border-slate-300 p-4 text-center">
            <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Référence</p>
            <p class="mt-1 font-mono text-xl font-semibold tracking-wider text-slate-900" data-testid="reference">{{ $serviceRequest->code }}</p>
        </div>

        <dl class="mt-6 space-y-2 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-slate-600">
                    {{ $serviceRequest->service->title }} : {{ $serviceRequest->quantity }} × {{ Money::format($serviceRequest->unit_price) }}
                </dt>
                <dd class="font-medium tabular-nums">{{ Money::format($serviceRequest->unit_price * $serviceRequest->quantity) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-slate-600">Frais de dossier</dt>
                <dd class="font-medium tabular-nums">{{ Money::format($serviceRequest->fees) }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-t border-slate-200 pt-3 text-lg">
                <dt class="font-semibold text-slate-900">Montant à payer</dt>
                <dd class="font-semibold text-emerald-700 tabular-nums" data-testid="amount">{{ Money::format($serviceRequest->amount) }}</dd>
            </div>
        </dl>

        <div class="mt-8 border-t border-slate-200 pt-6" aria-live="polite">
            {{-- Payée --}}
            <div x-show="state.status === 'PAID'" x-cloak class="rounded-xl bg-emerald-50 p-4 text-emerald-800" role="status">
                <p class="font-semibold">Paiement confirmé</p>
                <p class="mt-1 text-sm">Votre demande est payée. Elle va maintenant être traitée par nos services.</p>
            </div>

            {{-- Paiement en cours --}}
            <div x-show="state.status === 'PAYMENT_IN_PROGRESS'" x-cloak class="flex items-start gap-3 rounded-xl bg-sky-50 p-4 text-sky-900" role="status">
                <svg class="mt-0.5 size-5 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/>
                    <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                </svg>
                <div>
                    <p class="font-semibold">Paiement en cours</p>
                    <p class="mt-1 text-sm">
                        Validez la transaction sur le téléphone <span class="font-medium" x-text="state.payment?.phone_number"></span>.
                        Cette page se met à jour automatiquement.
                    </p>
                    <p class="mt-2 text-sm text-sky-700" x-show="pollingTimedOut">
                        La confirmation tarde à arriver. Vous pouvez revenir sur cette page plus tard avec votre référence.
                    </p>
                </div>
            </div>

            {{-- À payer (premier essai ou nouvel essai après un échec) --}}
            <form x-show="state.status === 'PENDING_PAYMENT'" x-cloak class="space-y-4" novalidate @submit.prevent="pay">
                <p
                    x-show="state.payment?.status === 'FAILED'"
                    class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"
                    role="alert"
                    x-text="state.payment?.failure_message"
                ></p>

                <div>
                    <label for="operator" class="block text-sm font-medium text-slate-700">Opérateur</label>
                    <select id="operator" x-model="operator" @change="operatorError = ''" class="mt-1 block w-full rounded-lg px-3 py-2.5 ring-1 ring-slate-300 focus:ring-2 focus:ring-emerald-600 focus:outline-none" :class="operatorError && 'ring-red-500'">
                        <option value="">Choisissez votre opérateur</option>
                        <template x-for="op in operators" :key="op.value">
                            <option :value="op.value" x-text="op.label"></option>
                        </template>
                    </select>
                    <p class="mt-1 text-sm text-red-600" x-show="operatorError" x-text="operatorError"></p>
                </div>

                <div>
                    <label for="phone_number" class="block text-sm font-medium text-slate-700">Numéro de téléphone</label>
                    <input
                        id="phone_number"
                        type="tel"
                        inputmode="numeric"
                        autocomplete="tel-national"
                        maxlength="14"
                        placeholder="01 23 45 67 89"
                        x-model="phoneNumber"
                        @input="phoneError = ''"
                        @blur="validatePhone(true)"
                        :aria-invalid="Boolean(phoneError)"
                        aria-describedby="phone_number-help phone_number-error"
                        class="mt-1 block w-full rounded-lg px-3 py-2.5 tracking-wide ring-1 ring-slate-300 focus:ring-2 focus:ring-emerald-600 focus:outline-none"
                        :class="phoneError && 'ring-red-500'"
                    >
                    <p id="phone_number-help" class="mt-1 text-xs text-slate-500">10 chiffres, commençant par 01.</p>
                    <p id="phone_number-error" class="mt-1 text-sm text-red-600" x-show="phoneError" x-text="phoneError"></p>
                </div>

                <p class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" role="alert" x-show="generalError" x-text="generalError"></p>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white transition hover:bg-emerald-800 focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="submitting"
                >
                    <span x-show="!submitting">Payer {{ Money::format($serviceRequest->amount) }}</span>
                    <span x-show="submitting">Envoi…</span>
                </button>
            </form>
        </div>

        <a href="{{ route('service-requests.create') }}" class="mt-8 inline-block text-sm font-medium text-emerald-700 hover:underline">
            Faire une nouvelle demande
        </a>
    </section>
@endsection
