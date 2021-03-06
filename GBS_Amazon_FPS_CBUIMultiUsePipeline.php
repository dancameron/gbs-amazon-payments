<?php

require_once('Amazon/CBUI/CBUIMultiUsePipeline.php');

class GBS_Amazon_FPS_CBUIMultiUsePipeline extends Amazon_FPS_CBUIMultiUsePipeline {
	public function set_sandbox( $sandbox = TRUE ) {
		if ( $sandbox ) {
			self::$CBUI_URL = "https://authorize.payments-sandbox.amazon.com/cobranded-ui/actions/start";
		} else {
			self::$CBUI_URL = "https://authorize.payments.amazon.com/cobranded-ui/actions/start";
		}
	}
}
