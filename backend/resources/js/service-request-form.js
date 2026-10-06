const moneyFormatter = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

/** Formate un montant en FCFA : 5000 donne « 5 000 F ». */
export const money = (amount) => `${moneyFormatter.format(amount)} F`;

/**
 * Formulaire de demande.
 *
 * Le total affiché est indicatif : le serveur recalcule toujours le montant
 * à partir du tarif en base. La validation front reprend les règles du back
 * pour un retour immédiat, sans s'y substituer.
 */
export default ({ services, fees, maxQuantity, storeUrl }) => ({
    services,
    fees,
    maxQuantity,
    serviceCode: '',
    quantity: 1,
    errors: {},
    generalError: '',
    submitting: false,

    money,

    get selectedService() {
        return this.services.find((service) => service.code === this.serviceCode) ?? null;
    },

    get validQuantity() {
        return Number.isInteger(this.quantity) && this.quantity >= 1 && this.quantity <= this.maxQuantity;
    },

    get subtotal() {
        return this.selectedService && this.validQuantity ? this.selectedService.price * this.quantity : 0;
    },

    get total() {
        return this.subtotal + this.fees;
    },

    hasError(field) {
        return Boolean(this.errors[field]);
    },

    clearError(field) {
        delete this.errors[field];
        this.generalError = '';
    },

    validate() {
        const errors = {};
        if (!this.selectedService) {
            errors.service_code = 'Veuillez choisir un service.';
        }
        if (!this.validQuantity) {
            errors.quantity = `La quantité doit être un nombre entier entre 1 et ${this.maxQuantity}.`;
        }
        this.errors = errors;

        return Object.keys(errors).length === 0;
    },

    async submit() {
        this.generalError = '';
        if (!this.validate()) {
            return;
        }

        this.submitting = true;
        try {
            const response = await fetch(storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ service_code: this.serviceCode, quantity: this.quantity }),
            });
            const payload = await response.json().catch(() => ({}));

            if (response.status === 201) {
                window.location.assign(payload.data.links.page);
                return;
            }
            if (response.status === 422) {
                this.errors = Object.fromEntries(
                    Object.entries(payload.errors ?? {}).map(([field, messages]) => [field, messages[0]]),
                );
                return;
            }
            this.generalError = payload.message ?? 'Une erreur est survenue. Veuillez réessayer.';
        } catch {
            this.generalError = 'Le service est momentanément indisponible. Vérifiez votre connexion et réessayez.';
        } finally {
            this.submitting = false;
        }
    },
});
