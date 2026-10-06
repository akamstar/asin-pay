import Alpine from 'alpinejs';
import serviceRequestForm from './service-request-form';

window.Alpine = Alpine;

Alpine.data('serviceRequestForm', serviceRequestForm);
Alpine.start();
