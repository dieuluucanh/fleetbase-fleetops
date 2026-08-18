import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { isArray } from '@ember/array';

export default class AnalyticsReportsIndexDetailsResultController extends Controller {
    @service fetch;
    @service notifications;

    @tracked tableRefreshable = null;
    @tracked result = null;

    setup() {
        if (isArray(this.model?.result_columns)) {
            this.result = {
                columns: this.model.result_columns,
                data: this.model.data ?? [],
                meta: this.model.meta ?? {},
            };
        }
        this.loadRefreshableStatus.perform();
    }

    @task *loadRefreshableStatus() {
        try {
            const tableName = this.model?.query_config?.table?.name;
            if (!tableName) {
                return;
            }

            const response = yield this.fetch.get('reports/tables', { extension: 'fleet-ops' });
            const tables = isArray(response?.tables) ? response.tables : [];
            const table = tables.find((t) => t.name === tableName);
            this.tableRefreshable = table?.refreshable ?? null;
        } catch (e) {
            // Silently fail - refresh button simply won't appear
        }
    }

    @task *refreshAndExecute() {
        const report = this.model;

        if (!report?.id) {
            this.notifications.warning('No report to refresh.');
            return;
        }

        try {
            // Step 1: Refresh the data source
            const tableName = report.query_config?.table?.name;
            if (!tableName) {
                this.notifications.error('No data source selected');
                return;
            }

            const refreshResult = yield this.fetch.post('reports/refresh-data-source', {
                table_name: tableName,
                date: 'today',
            });

            if (!refreshResult?.success) {
                this.notifications.error(refreshResult?.error?.message ?? 'Failed to refresh data source');
                return;
            }

            // Step 2: Re-execute the query with fresh data
            const result = yield report.execute();
            report.fillResult(result);
            this.result = result;
            this.notifications.success('Report refreshed successfully');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

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
