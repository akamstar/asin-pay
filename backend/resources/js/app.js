import Alpine from 'alpinejs';
import paymentPanel from './payment-panel';
import serviceRequestForm from './service-request-form';

window.Alpine = Alpine;

Alpine.data('serviceRequestForm', serviceRequestForm);
Alpine.data('paymentPanel', paymentPanel);
Alpine.start();
