const PHONE_NUMBER_PATTERN = /^01\d{8}$/;
const POLL_INTERVAL_MS = 2000;
const POLL_TIMEOUT_MS = 3 * 60 * 1000;

/** Supprime les séparateurs usuels de saisie : « 01 02 03 04 05 » donne « 0102030405 ». */
export const normalizePhoneNumber = (value) => String(value ?? '').replace(/[\s.-]/g, '');

export const isValidPhoneNumber = (value) => PHONE_NUMBER_PATTERN.test(normalizePhoneNumber(value));

/**
 * Paiement d'une demande : saisie du numéro, lancement du paiement, puis suivi du
 * statut par polling jusqu'au résultat (payée, ou échec avec possibilité de réessayer).
 * La validation front reprend la règle du back (01 + 8 chiffres) sans s'y substituer.
 */
export default ({ state, operators, paymentUrl, statusUrl }) => ({
    state,
    operators,
    operator: '',
    operatorError: '',
    phoneNumber: '',
    phoneError: '',
    generalError: '',
    submitting: false,
    pollingTimedOut: false,
    pollTimer: null,
    pollStartedAt: null,

    init() {
        // Page rechargée pendant un paiement : le suivi reprend automatiquement.
        if (this.state.status === 'PAYMENT_IN_PROGRESS') {
            this.startPolling();
        }
    },

    destroy() {
        clearTimeout(this.pollTimer);
    },

    validatePhone(onlyIfFilled = false) {
        if (onlyIfFilled && this.phoneNumber.trim() === '') {
            return true;
        }
        this.phoneError = isValidPhoneNumber(this.phoneNumber) ? '' : 'Le numéro doit contenir 10 chiffres et commencer par 01.';

        return this.phoneError === '';
    },

    async pay() {
        this.generalError = '';
        this.operatorError = this.operator ? '' : 'Veuillez choisir votre opérateur.';
        if (!this.validatePhone() || this.operatorError) {
            return;
        }

        this.submitting = true;
        try {
            const response = await fetch(paymentUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ phone_number: normalizePhoneNumber(this.phoneNumber), operator: this.operator }),
            });
            const payload = await response.json().catch(() => ({}));

            if (response.status === 202) {
                this.state = payload.data;
                this.startPolling();
                return;
            }
            if (response.status === 422) {
                this.phoneError = payload.errors?.phone_number?.[0] ?? '';
                this.operatorError = payload.errors?.operator?.[0] ?? '';
                return;
            }
            if (response.status === 409) {
                await this.refresh();
            }
            this.generalError = payload.message ?? 'Une erreur est survenue. Veuillez réessayer.';
        } catch {
            this.generalError = 'Le service est momentanément indisponible. Vérifiez votre connexion et réessayez.';
        } finally {
            this.submitting = false;
        }
    },

    startPolling() {
        this.pollingTimedOut = false;
        this.pollStartedAt = Date.now();
        this.scheduleNextPoll();
    },

    scheduleNextPoll() {
        clearTimeout(this.pollTimer);
        this.pollTimer = setTimeout(() => this.poll(), POLL_INTERVAL_MS);
    },

    async poll() {
        await this.refresh();

        if (this.state.status !== 'PAYMENT_IN_PROGRESS') {
            return;
        }
        if (Date.now() - this.pollStartedAt > POLL_TIMEOUT_MS) {
            this.pollingTimedOut = true;
            return;
        }
        this.scheduleNextPoll();
    },

    async refresh() {
        try {
            const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            if (response.ok) {
                this.state = (await response.json()).data;
            }
        } catch {
            // Erreur réseau ponctuelle : la prochaine interrogation réessaiera.
        }
    },
});
