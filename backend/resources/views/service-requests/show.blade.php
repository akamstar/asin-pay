@php
    use App\Enums\ServiceRequestStatus;
    use App\Support\Money;
@endphp

@extends('layouts.app')

@section('title', 'Demande '.$serviceRequest->code)

@section('content')
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Demande enregistrée</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Conservez votre référence : elle vous permet de retrouver votre demande.
                </p>
            </div>
            <span @class([
                'rounded-full px-3 py-1 text-sm font-medium',
                'bg-amber-100 text-amber-800' => $serviceRequest->status === ServiceRequestStatus::PendingPayment,
                'bg-sky-100 text-sky-800' => $serviceRequest->status === ServiceRequestStatus::PaymentInProgress,
                'bg-emerald-100 text-emerald-800' => $serviceRequest->status === ServiceRequestStatus::Paid,
            ])>
                {{ $serviceRequest->status->label() }}
            </span>
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

        <a href="{{ route('service-requests.create') }}" class="mt-8 inline-block text-sm font-medium text-emerald-700 hover:underline">
            Faire une nouvelle demande
        </a>
    </section>
@endsection
