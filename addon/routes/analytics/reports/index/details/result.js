import Route from '@ember/routing/route';

export default class AnalyticsReportsIndexDetailsResultRoute extends Route {
    setupController(controller) {
        super.setupController(...arguments);
        controller.setup();
    }
}
