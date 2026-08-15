import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class AnalyticsReportsIndexDetailsResultController extends Controller {
    @service fetch;
    @service notifications;

    @action async export(format = 'csv') {
        const report = this.model;

        if (!report?.id) {
            this.notifications.warning('No report to export.');
            return;
        }

        try {
            const response = await report.export(format);

            if (response?.success === false) {
                this.notifications.serverError(response);
                return;
            }

            await this.fetch.download(`reports/export-download/${response.filename}`, {}, { method: 'GET' });
            this.notifications.success('Report exported successfully');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
