<?php
namespace Aelia\WC\EU_VAT_Assistant\Reports;
if(!defined('ABSPATH')) exit; // Exit if accessed directly

use Aelia\WC\EU_VAT_Assistant\WC_Aelia_EU_VAT_Assistant;
use Aelia\WC\EU_VAT_Assistant\Settings;
use Aelia\WC\EU_VAT_Assistant\Definitions;

/**
 * Base class for the sales summary report.
 *
 * @since 1.5.8.160112
 */
abstract class Base_Sales_Summary_Report extends \Aelia\WC\EU_VAT_Assistant\Reports\Base_Sales_Report {
	const SALES_SUMMARY_REPORT_TEMP_TABLE = 'aelia_euva_sales_summary_report';

	/**
	 * Indicates if the tax passed as a parameter should be skipped (i.e. excluded
	 * from the report).
	 *
	 * @param array tax_details An array of data describing a tax.
	 * @return bool True (tax should be excluded from the report) or false (tax
	 * should be displayed on the report).
	 */
	protected function should_skip($order_data) {
		return false;
	}

	/**
	 * Creates the temporary table that will be used to generate the VIES report.
	 *
	 * @return string|bool The name of the created table, or false on failure.
	 * @since 1.3.20.150330
	 */
	protected function create_temp_report_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::SALES_SUMMARY_REPORT_TEMP_TABLE;
		$sql = "
			CREATE TEMPORARY TABLE IF NOT EXISTS `$table_name` (
				`row_id` INT NOT NULL AUTO_INCREMENT,
				`order_id` INT NOT NULL,
				`post_type` VARCHAR(50) NOT NULL,
				`is_eu_country` VARCHAR(10) NOT NULL,
				`billing_country` VARCHAR(10) NOT NULL,
				`vat_number` VARCHAR(50) NOT NULL,
				`vat_number_validated` VARCHAR(50) NOT NULL,
				`order_item_id` INT NOT NULL,
				`line_total` DECIMAL(18,6) NOT NULL,
				`line_tax` DECIMAL(18,6) NOT NULL,
				`tax_rate` DECIMAL(18,2) NOT NULL,
				`exchange_rate` DECIMAL(18,6) NOT NULL,
				PRIMARY KEY (`row_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
		";

		return $this->create_temporary_table($table_name, $sql);
	}

	/**
	 * Stores a row in the temporary table used to produce the VIES report.
	 *
	 * @since 1.3.20.150330
	 */
	protected function store_temp_data(array $fields) {
		global $wpdb;

		// Debug
		//var_dump("STORING TEMP. TAX DATA", $fields);

		$table_name = $wpdb->prefix . self::SALES_SUMMARY_REPORT_TEMP_TABLE;
		$SQL = "
			INSERT INTO `$table_name` (
				`order_id`,
				`post_type`,
				`is_eu_country`,
				`billing_country`,
				`vat_number`,
				`vat_number_validated`,
				`order_item_id`,
				`line_total`,
				`line_tax`,
				`tax_rate`,
				`exchange_rate`
			)
			VALUES (
				%d, -- Order ID
				%s, -- Post type (for debugging purposes)
				%s, -- Is EU country (flag)
				%s, -- Billing country
				%d, -- Order item ID
				%s, -- VAT Number
				%s, -- VAT Number validated (flag)
				%f, -- Line total
				%f, -- Line tax
				%f, -- Tax rate
				%f -- Exchange rate
			)
		";

		$query = $wpdb->prepare(
			$SQL,
			$fields['order_id'],
			$fields['post_type'],
			$fields['is_eu_country'],
			$fields['billing_country'],
			$fields['vat_number'],
			$fields['vat_number_validated'],
			$fields['order_item_id'],
			$fields['line_total'],
			$fields['line_tax'],
			$fields['tax_rate'],
			$fields['exchange_rate']
		);

		// Debug
		//var_dump($fields, $query);die();

		// Save to database the IP data for the country
		$rows_affected = $wpdb->query($query);

		$result = $rows_affected;
		if($result == false) {
			$error_message = sprintf(__('Could not store row in table "%s" rates. ' .
																	'Fields (JSON): "%s".', $this->text_domain),
															 $table_name,
															 $fields);
			$this->log($error_message, false);
			trigger_error(E_USER_WARNING, $error_message);
		}
		return $result;
	}

	/**
	 * Returns the meta keys of the order items that should be loaded by the report.
	 * For this report, line totals and cost indicate the price of products and
	 * the price of shipping, respectively.
	 *
	 * @return array
	 */
	protected function get_order_items_meta_keys() {
		return array(
			// _line_total: total charged for order items
			'_line_total',
			// cost: total charged for shipping
			'cost',
		);
	}

	/**
	 * Stores in a temporary table the data required to produce the VIES report.
	 *
	 * @param array dataset An array containing the data for the report.
	 * @return bool True if the data was stored correctly, false otherwise.
	 * @since 1.3.20.150402
	 */
	protected function store_report_data($dataset) {
		foreach($dataset as $index => $entry) {
			$entry->eu_vat_data = maybe_unserialize($entry->eu_vat_data);
			$entry->eu_vat_evidence = maybe_unserialize($entry->eu_vat_evidence);

			//var_dump($entry->eu_vat_data);

			if(!$this->should_skip($entry)) {
				$vat_currency_exchange_rate = (float)get_value('vat_currency_exchange_rate', $entry->eu_vat_data);
				if(!is_numeric($vat_currency_exchange_rate) || ($vat_currency_exchange_rate <= 0)) {
					$this->log(sprintf(__('VAT currency exchange rate not found for order id "%s". ' .
																'Fetching exchange rate from FX provider.', $this->text_domain),
														 $entry->order_id));
					$vat_currency_exchange_rate = $this->get_vat_currency_exchange_rate($entry->order_currency,
																																							$entry->order_date);
				}

				$fields = array(
					'order_id' => $entry->order_id,
					'post_type' => $entry->post_type,
					'is_eu_country' => $this->is_eu_country($entry->eu_vat_evidence['location']['billing_country']) ? 'eu' : 'non-eu',
					'billing_country' => $entry->eu_vat_evidence['location']['billing_country'],
					'vat_number' => $entry->eu_vat_evidence['exemption']['vat_number'],
					'vat_number_validated' => $entry->vat_number_validated,
					'order_item_id' => $entry->order_item_id,
					'exchange_rate' => $vat_currency_exchange_rate,
				);

				/* Calculate the line tax
				 * This operation is necessary because shipping taxes are stored as an
				 * array. The amounts have to be unserialised and summed.
				 */
				// TODO This logic doesn't support compounding tax rates and should be reviewed.
				$line_tax = maybe_unserialize($entry->line_tax);
				if(is_array($line_tax)) {
					$line_tax = array_sum($line_tax);
				}

				/* Calculate the tax rate
				 * A tax rate lower than zero means that the actual rate could not be
				 * calculated via SQL. This is often the case when the item is a shipping
				 * cost, as its tax is stored as an array, insteaf of a number (see
				 * above).
				 */
				if($entry->tax_rate < 0) {
					$entry->tax_rate = 0;
					if($entry->line_total > 0) {
						$entry->tax_rate = round($line_tax / $entry->line_total * 100, 2);
					}
				}

				// Add the tax information to the data
				$fields = array_merge($fields, array(
					'line_total' => $entry->line_total,
					'line_tax' => $line_tax,
					'tax_rate' => $entry->tax_rate,
				));

				if(!$this->store_temp_data($fields)) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Returns the sales data that will be included in the report. This method must
	 * be implemented by descendant classes.
	 *
	 * @return array
	 */
	protected function get_sales_data() {
		return array();
	}

	/**
	 * Returns the refunds data that will be included in the report. This method
	 * is empty for compatibility with WooCommerce 2.1, which doesn't handle
	 * refunds. Classes designed for WooCommerce 2.2 and later will take care of
	 * fetching the refunds.
	 *
	 * @return array
	 * @since 1.3.20.150330
	 */
	protected function get_refunds_data() {
		return array();
	}

	/**
	 * Consolidates the sales data with the refunds data and returns it.
	 *
	 * @return array An array containing the consolidated sales and return data.
	 * @since 1.3.20.150330
	 */
	protected function get_sales_summary_report_data() {
		global $wpdb;

		$px = $wpdb->prefix;
		$SQL = "
			SELECT
				SSR.is_eu_country
				,SSR.billing_country
				,SSR.tax_rate
				,SUM(SSR.line_total * SSR.exchange_rate) AS sales_total
				,SUM(SSR.line_tax * SSR.exchange_rate) AS tax_total
			FROM
				{$px}" . self::SALES_SUMMARY_REPORT_TEMP_TABLE . " SSR
			GROUP BY
				SSR.is_eu_country
				,SSR.billing_country
				,SSR.tax_rate
			HAVING
				-- Discard rows with zero, they don't need to be added to the report.
				-- We can't just use 'greater than zero' as a criteria, because rows
				-- with negative values must be included
				(sales_total <> 0)
			ORDER BY
				SSR.is_eu_country
				,SSR.tax_rate
				,SSR.billing_country
		";

		// Debug
		//var_dump($SQL);die();
		$dataset = $wpdb->get_results($SQL);

		// Debug
		//var_dump("REFUNDS RESULT", $dataset);
		return $dataset;
	}

	/**
	 * Loads and returns the report data.
	 *
	 * @return array An array with the report data.
	 * @since 1.3.20.150402
	 */
	protected function get_report_data() {
		if($result = $this->create_temp_report_table()) {
			// Retrieve and store sales data
			$result = $this->store_report_data($this->get_sales_data());

			// Retrieve and store refunds data
			if($result) {
				$result = $this->store_report_data($this->get_refunds_data());
			}

			if($result) {
				// Prepare a summary for the VIES report and return it
				$result = $this->get_sales_summary_report_data();
			}
			return $result;
		}

		if(!$result) {
			trigger_error(E_USER_WARNING, __('Could not prepare temporary table for the report. ' .
																			 'Please enable debug mode and tru again. If the issue ' .
																			 'persists, contact support and forward them the debug ' .
																			 'log produced by the plugin. For more information, please ' .
																			 'go to WooCommerce > EU VAT Assistant > Support.',
																			 $this->text_domain));
		}
	}

	/**
	 * Get the data for the report.
	 *
	 * @return string
	 */
	public function get_main_chart() {
		$sales_summary_report_data = $this->get_report_data();

		// Keep track of the report columns. This information will be used to adjust
		// the "colspan" property
		$report_columns = 6;
		$debug_columns_class = $this->debug ? '' : ' hidden ';
		?>
		<div id="sales_summary_report" class="wc_aelia_eu_vat_assistant report">
			<table class="widefat">
				<thead>
					<tr class="report_information">
						<th colspan="<?php echo $report_columns; ?>">
							<ul>
								<li>
									<span class="label"><?php
										echo __('Currency for VAT returns:', $this->text_domain);
									?></span>
									<span><?php echo $this->vat_currency(); ?></span>
								</li>
								<li>
									<span class="label"><?php
										echo __('Exchange rates used:', $this->text_domain);
									?></span>
									<span><?php
										if($this->should_use_orders_exchange_rates()) {
											echo __('Rates saved with each order', $this->text_domain);
										}
										else {
											echo __('ECB rates for each quarter', $this->text_domain);
										}
									?></span>
								</li>
							</ul>
						</th>
					</tr>
					<tr class="column_headers">
						<th class="is_eu <?php echo $debug_columns_class; ?>"><?php echo __('EU', $this->text_domain); ?></th>
						<th class="billing_country"><?php echo __('Customer country', $this->text_domain); ?></th>
						<th class="tax_rate total_row column_group left right"><?php echo __('Tax rate', $this->text_domain); ?></th>
						<th class="total_sales total_row "><?php echo __('Total Sales (ex. tax)', $this->text_domain); ?></th>
						<th class="total_tax total_row "><?php echo __('Total Tax', $this->text_domain); ?></th>
						<th class="total_tax total_row inc_tax column_group left"><?php echo __('Total Sales (inc. tax)', $this->text_domain); ?></th>
					</tr>
				</thead>
				<?php if(empty($sales_summary_report_data)) : ?>
					<tbody>
						<tr>
							<td colspan="<?php echo $report_columns; ?>"><?php echo __('No sales have been found for the selected period.', $this->text_domain); ?></td>
						</tr>
					</tbody>
				<?php else : ?>
					<tbody>
						<?php

						$sales_total = 0;
						$taxes_total = 0;
						$render_group = null;
						foreach($sales_summary_report_data as $entry_id => $entry) {
							if($render_group != $entry->is_eu_country) {
								$this->render_group_header($entry->is_eu_country, $report_columns);
								$render_group = $entry->is_eu_country;
							}

							$sales_total += $entry->sales_total;
							$taxes_total += $entry->tax_total;
							?>
							<tr>
								<th class="is_eu <?php echo $debug_columns_class; ?>"><?php echo $entry->is_eu_country; ?></th>
								<th class="billing_country"><?php echo $entry->billing_country; ?></th>
								<th class="tax_rate total_row column_group left right"><?php echo $entry->tax_rate; ?></th>
								<th class="total_sales total_row "><?php echo $this->format_price($entry->sales_total); ?></th>
								<th class="total_tax total_row "><?php echo $this->format_price($entry->tax_total); ?></th>
								<th class="total_sales total_row inc_tax column_group left"><?php echo $this->format_price($entry->sales_total + $entry->tax_total); ?></th>
							</tr>
							<?php
						} // First loop - END
						?>
					</tbody>
					<tfoot>
						<tr>
							<th class="label column_group right" colspan="2"><?php echo __('Totals', $this->text_domain); ?></th>
							<th class="total total_row"><?php echo $this->format_price($sales_total); ?></th>
							<th class="total total_row"><?php echo $this->format_price($taxes_total); ?></th>
							<th class="total total_row column_group left"><?php echo $this->format_price($sales_total + $taxes_total); ?></th>
						</tr>
					</tfoot>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders a header on top of the standard reporting UI.
	 */
	protected function render_ui_header() {
		include(WC_Aelia_EU_VAT_Assistant::instance()->path('views') . '/admin/reports/sales-summary-report-header.php');
	}

	/**
	 * Renders a group header, to organise the data displayed in the report.
	 *
	 * @param string group_id The group ID. Each group ID will show a different
	 * text.
	 * @param int report_columns The number of columns in the report. Used to
	 * determine the "colspan" of the group header.
	 */
	protected function render_group_header($group_id, $report_columns) {
		$group_header_content = array(
			'eu' => array(
				'title' => __('EU Sales', $this->text_domain),
				'description' => __('This section shows sales made to EU countries.', $this->text_domain),
			),
			'non-eu' => array(
				'title' => __('Non-EU Sales', $this->text_domain),
				'description' => __('This section shows sales made to countries outside the EU.', $this->text_domain),
			),
		);

		$content = get_value($group_id, $group_header_content);
		if(empty($content)) {
			return;
		}
		?>
		<tr class="group_header">
			<th class="" colspan="<?php echo $report_columns; ?>">
				<div class="title"><?php
					echo $content['title'];
				?></div>
				<div class="description"><?php
					echo $content['description'];
				?></div>
			</th>
		</tr>
		<?php
	}
}
