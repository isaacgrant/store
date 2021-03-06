<?php

/**
 *
 *
 * @package   Store
 * @copyright 2006-2016 silverorange
 */
abstract class StoreQuickOrderPage extends SiteArticlePage
{
	// {{{ protected properties

	protected $form_xml = 'Store/pages/quick-order.xml';
	protected $cart_xml = 'Store/pages/quick-order-cart.xml';
	protected $message_display;

	protected $form_ui;
	protected $cart_ui;

	protected $num_rows = 10;
	protected $items_added = array();
	protected $items_saved = array();

	// }}}

	// init phase
	// {{{ public function init()

	public function init()
	{
		parent::init();

		$this->form_ui = new SwatUI();
		$this->form_ui->loadFromXML($this->form_xml);

		$form = $this->form_ui->getWidget('quick_order_form');
		$form->action = $this->source;

		$view = $this->form_ui->getWidget('quick_order_view');
		$view->model = $this->getQuickOrderTableStore();
		$column = $view->getColumn('item_selector_column');
		$item_selector =
			$column->getRendererByPosition()->getPrototypeWidget();

		$this->initItemSelector($item_selector);

		$this->form_ui->init();

		$this->cart_ui = new SwatUI();
		$this->cart_ui->loadFromXML($this->cart_xml);
		$this->cart_ui->init();

		$this->message_display = new SwatMessageDisplay();
		$this->message_display->id = 'cart_message_display';
		$this->message_display->init();
	}

	// }}}
	// {{{ protected function initItemSelector()

	protected function initItemSelector(StoreQuickOrderItemSelector $selector)
	{
		$selector->db = $this->app->db;
		$selector->region = $this->app->getRegion();
		$selector->sku = null;
	}

	// }}}
	// {{{ protected function getQuickOrderTableStore()

	/**
	 *
	 * @return SwatTableStore
	 */
	protected function getQuickOrderTableStore()
	{
		$store = new SwatTableStore();

		for ($i = 0; $i < $this->num_rows; $i++) {
			$row = new stdClass();
			$row->id = $i;
			$store->add($row);
		}

		return $store;
	}

	// }}}

	// process phase
	// {{{ public function process()

	public function process()
	{
		parent::process();
		$this->message_display->process();
		$this->processForm();
	}

	// }}}
	// {{{ protected function processForm()

	protected function processForm()
	{
		$this->form_ui->process();

		$form = $this->form_ui->getWidget('quick_order_form');
		$view = $this->form_ui->getWidget('quick_order_view');

		$quantity_column = $view->getColumn('quantity_column');
		$quantity_renderer = $quantity_column->getRenderer('renderer');

		$item_selector_column = $view->getColumn('item_selector_column');
		$item_selector_renderer =
			$item_selector_column->getRendererByPosition();

		$sku_column = $view->getColumn('sku_column');
		$sku_renderer = $sku_column->getRenderer('renderer');

		if ($form->isProcessed()) {
			foreach ($sku_renderer->getClonedWidgets() as $id => $sku_widget) {
				$item_selector = $item_selector_renderer->getWidget($id);
				$sku = $sku_widget->value;

				if ($sku !== null) {
					$sku = trim($sku);
					$normalized_sku = $this->normalizeSku($sku);
					if ($normalized_sku == '') {
						$normalized_sku = null;
					}
				} else {
					$normalized_sku = null;
				}

				$quantity_widget = $quantity_renderer->getWidget($id);
				$quantity = $quantity_widget->value;

				// populate item flydown
				if ($normalized_sku !== null) {
					$item_selector->sku = $normalized_sku;
					$item_selector->db = $this->app->db;
					$item_selector->region = $this->app->getRegion();
					$item_selector->init();
					$item_selector->process();
				}

				$item_id = $item_selector->value;

				// item selector did not load using ajax so try to guess the
				// id based on the sku entered by the user
				if ($item_id === null && $normalized_sku !== null) {
					$item_id = $this->getItemId($normalized_sku);
					if ($item_id === null) {
						$message = $this->getNotFoundErrorMessage($sku);
						$sku_widget->addMessage($message);
					}
				}

				if ($item_id !== null && !$sku_renderer->hasMessage($id) &&
					!$quantity_renderer->hasMessage($id) &&
					$this->addItem($item_id, $quantity, $normalized_sku)) {
					// clear fields after a successful add
					$sku_widget->value = '';
					$quantity_widget->value = 1;
					$item_selector->sku = null;
					$item_selector->init();
				}
			}

			if ($form->hasMessage()) {
				$message = new SwatMessage(Store::_('There is a problem with '.
					'one or more of the items you requested.'), 'error');

				$message->secondary_content = Store::_('Please address the '.
					'fields highlighted below and re-submit the form.');

				$this->message_display->add($message);
			}
		}
	}

	// }}}
	// {{{ protected function getNotFoundErrorMessage()

	protected function getNotFoundErrorMessage($sku)
	{
		$message = new SwatMessage(sprintf(Store::_(
			'“%s” is not an available %%s.'), $sku), 'error');

		return $message;
	}

	// }}}
	// {{{ protected function getItemId()

	/**
	 * Gets the item id for a given sku
	 *
	 * @param string $sku the sku of the item to get.
	 *
	 * @return integer the id of the item with the given sku or null if no
	 *                  item is found.
	 */
	protected function getItemId($sku)
	{
		$sku = mb_strtolower($sku);
		if (mb_substr($sku, 0, 1) === '#' && mb_strlen($sku) > 1)
			$sku = mb_substr($sku, 1);

		$sql = sprintf('select Item.id from Item
			inner join VisibleProductCache on
				Item.product = VisibleProductCache.product and
					VisibleProductCache.region = %1$s
			where lower(Item.sku) = %2$s
				or Item.id in (select item from ItemAlias where
				lower(ItemAlias.sku) = %2$s)
			order by part_count asc
			limit 1',
			$this->app->db->quote($this->app->getRegion()->id, 'integer'),
			$this->app->db->quote($sku, 'text'));

		$item = SwatDB::queryOne($this->app->db, $sql);
		return $item;
	}

	// }}}
	// {{{ protected function addItem()

	protected function addItem($item_id, $quantity, $sku)
	{
		$cart = $this->app->cart;
		$cart_entry = $this->getCartEntry($item_id, $quantity, $sku);

		if ($cart_entry->item->hasAvailableStatus()) {
			$added_entry = $cart->checkout->addEntry($cart_entry);

			if ($added_entry !== null)
				$this->items_added[] = $added_entry->item;
		} elseif (isset($cart->saved)) {
			$added_entry = $cart->saved->addEntry($cart_entry);

			if ($added_entry !== null)
				$this->items_saved[] = $added_entry->item;
		}

		return $added_entry;
	}

	// }}}
	// {{{ protected function getCartEntry()

	protected function getCartEntry($item_id, $quantity, $sku)
	{
		$cart_entry_class = SwatDBClassMap::get('StoreCartEntry');
		$cart_entry = new $cart_entry_class();

		$this->app->session->activate();

		if ($this->app->session->isLoggedIn()) {
			$cart_entry->account = $this->app->session->getAccountId();
		} else {
			$cart_entry->sessionid = $this->app->session->getSessionId();
		}

		$item_class = SwatDBClassMap::get('StoreItem');
		$item = new $item_class();
		$item->setDatabase($this->app->db);
		$item->setRegion($this->app->getRegion(), false);
		$item->load($item_id);

		// explicitly load product to get product path information
		$product_wrapper = SwatDBClassMap::get('StoreProductWrapper');
		$sql = sprintf('select id, title, shortname,
				getCategoryPath(primary_category) as path,
				catalog
			from Product
				left outer join ProductPrimaryCategoryView on product = id
			where id = %s',
			$this->app->db->quote(
				$item->getInternalValue('product'), 'integer'));

		$products = SwatDB::query($this->app->db, $sql, $product_wrapper);
		$product = $products->getFirst();
		$item->product = $product;

		$cart_entry->item   = $item;
		$cart_entry->source = StoreCartEntry::SOURCE_QUICK_ORDER;
		$cart_entry->setQuantity($quantity);

		if ($sku != $cart_entry->item->sku) {
			$sql = sprintf('select * from ItemAlias where sku = %s',
				$this->app->db->quote($sku, 'text'));

			$item_alias = SwatDB::query($this->app->db, $sql,
				SwatDBClassMap::get('StoreItemAliasWrapper'));

			if ($item_alias !== null)
				$cart_entry->alias = $item_alias->getFirst();
		}

		return $cart_entry;
	}

	// }}}
	// {{{ protected function normalizeSku()

	protected function normalizeSku($sku)
	{
		if (mb_substr($sku, 0, 1) === '#' && mb_strlen($sku) > 1)
			$sku = mb_substr($sku, 1);

		return $sku;
	}

	// }}}

	// build phase
	// {{{ public function build()

	public function build()
	{
		parent::build();

		$this->buildCartView();
		$this->buildQuickOrderView();

		$this->layout->startCapture('content', true);
		$this->message_display->display();
		$this->layout->endCapture();

		$this->layout->startCapture('content');
		$this->form_ui->display();
		Swat::displayInlineJavaScript($this->getInlineJavaScript());
		$this->layout->endCapture();
	}

	// }}}
	// {{{ protected function buildCartView()

	protected function buildCartView()
	{
		foreach ($this->app->cart->checkout->getMessages() as $message)
			$this->message_display->add($message);

		$cart_view = $this->cart_ui->getWidget('cart_view');
		$cart_view->model = $this->getCartTableStore();

		$count = count($cart_view->model);
		if ($count > 0) {
			$message = new SwatMessage(null, 'cart');
			$message->primary_content = Store::ngettext(
				'The following item was added to your cart:',
				'The following items were added to your cart:',
				$count);

			ob_start();
			$this->cart_ui->display();

			echo '<div class="cart-message-links">';
			$this->displayCartLinks();
			echo '</div>';

			$message->secondary_content = ob_get_clean();
			$message->content_type = 'text/xml';
			$this->message_display->add($message);
		}

		if (count($this->items_saved) > 0) {
			$items = ngettext('item', 'items', count($this->items_saved));
			$number = SwatString::minimizeEntities(ucwords(
						Numbers_Words::toWords(count($this->items_saved))));

			$message = new SwatMessage(
				sprintf('%s %s has been saved for later.', $number, $items),
				'cart');

			$this->message_display->add($message);
		}
	}

	// }}}
	// {{{ protected function displayCartLinks()

	protected function displayCartLinks()
	{
		printf(Store::_('%sView your shopping cart%s or %sproceed to the '.
			'checkout%s.'),
			'<a href="cart">', '</a>',
			'<a href="checkout">', '</a>');
	}

	// }}}
	// {{{ protected function buildQuickOrderView()

	protected function buildQuickOrderView()
	{
		$view = $this->form_ui->getWidget('quick_order_view');

		if (count($view->model) == 0)
			$this->form_ui->getWidget('quick_order_form')->visible = false;
	}

	// }}}
	// {{{ protected function getCartTableStore()

	protected function getCartTableStore()
	{
		$ids = array();
		foreach ($this->items_added as $item)
			$ids[] = $item->id;

		$store = new SwatTableStore();

		$entries = $this->app->cart->checkout->getEntries();

		foreach ($entries as $entry) {
			// filter entries by added items
			if (in_array($entry->item->id, $ids)) {
				$ds = $this->getCartDetailsStore($entry);
				$store->add($ds, $entry->item->id);
			}
		}

		return $store;
	}

	// }}}
	// {{{ protected function getCartDetailsStore()

	protected function getCartDetailsStore(StoreCartEntry $entry)
	{
		$ds = new SwatDetailsStore($entry);

		$ds->quantity     = $entry->getQuantity();
		$ds->description  = $this->getEntryDescription($entry);
		$ds->price        = $entry->getCalculatedItemPrice();
		$ds->extension    = $entry->getExtension();
		$ds->product_link = $this->app->config->store->path.$entry->item->product->path;

		if ($entry->alias !== null) {
			$ds->item->sku = $entry->item->sku;
			$ds->item->sku.= ' ('.$entry->alias->sku.')';
		}

		return $ds;
	}

	//}}}
	// {{{ protected function getCartTableStoreRow()

	/**
	 * @deprecated Use StoreQuickOrderPage::getCartDetailsStore()
	 */
	protected function getCartTableStoreRow(StoreCartEntry $entry)
	{
		return $this->getCartDetailsStore($entry);
	}

	//}}}
	// {{{ protected function getEntryDescription()

	protected function getEntryDescription(StoreCartEntry $entry)
	{
		$description = array();
		foreach ($entry->item->getDescriptionArray() as $element)
			$description[] = SwatString::minimizeEntities($element);

		return implode(' - ', $description);
	}

	// }}}
	// {{{ protected function getInlineJavaScript()

	protected function getInlineJavaScript()
	{
		static $translations_displayed = false;

		$id = 'quick_order';
		$item_selector_id = 'item_selector';

		$javascript = '';
		if (!$translations_displayed) {
			$javascript.= sprintf("StoreQuickOrder.loading_text = '%s';\n",
				Store::_('loading …'));

			$translations_displayed = true;
		}

		$javascript.= sprintf(
			"var %s_obj = new StoreQuickOrder('%s', '%s', %s);",
			$id, $id, $item_selector_id, $this->num_rows);

		return $javascript;
	}

	// }}}

	// finalize phase
	// {{{ public function finalize()

	public function finalize()
	{
		parent::finalize();
		$this->layout->addHtmlHeadEntrySet(XML_RPCAjax::getHtmlHeadEntrySet());

		$yui = new SwatYUI(array('event', 'animation'));
		$this->layout->addHtmlHeadEntrySet($yui->getHtmlHeadEntrySet());

		$this->layout->addHtmlHeadEntry(
			'packages/store/javascript/store-quick-order-page.js'
		);

		$this->layout->addHtmlHeadEntry('packages/store/styles/store-cart.css');
		$this->layout->addHtmlHeadEntry(
			'packages/store/styles/store-quick-order-page.css'
		);

		$this->layout->addHtmlHeadEntry(
			'packages/store/styles/store-item-price-cell-renderer.css'
		);

		$this->layout->addHtmlHeadEntrySet(
			$this->message_display->getHtmlHeadEntrySet()
		);

		$this->layout->addHtmlHeadEntrySet(
			$this->cart_ui->getRoot()->getHtmlHeadEntrySet()
		);

		$this->layout->addHtmlHeadEntrySet(
			$this->form_ui->getRoot()->getHtmlHeadEntrySet()
		);
	}

	// }}}
}

?>
