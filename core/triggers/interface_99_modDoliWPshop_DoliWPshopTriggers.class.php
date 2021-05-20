<?php
/* Copyright (C) 2021 EOXIA <dev@eoxia.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */


/**
 * \file    core/triggers/interface_99_modDoliWPshop_DoliWPshopTriggers.class.php
 * \ingroup doliwpshop
 * \brief   DoliWPshop trigger.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 *  Class of triggers for DoliWPshop module
 */
class InterfaceDoliWPshopTriggers extends DolibarrTriggers
{
	/**
	 * @var DoliDB Database handler
	 */
	protected $db;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "demo";
		$this->description = "Doliwpshop triggers.";
		$this->version = '1.1.1';
		$this->picto = 'doliwpshop@doliwpshop';
	}

	/**
	 * Trigger name
	 *
	 * @return string Name of trigger file
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc()
	{
		return $this->description;
	}

	/**
	 * Function called when a Dolibarrr business event is done.
	 * All functions "runTrigger" are triggered if file
	 * is inside directory core/triggers
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		<0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (empty($conf->doliwpshop->enabled)) return 0; // If module is not enabled, we do nothing

		// Data and type of action are stored into $object and $action

		switch ($action) {
			case 'PAYMENTONLINE_PAYMENT_OK' :
				dol_syslog("Trigger '".$this->name."' for action '$action' launched by ".__FILE__.". id=".$object->id);
				require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

				$TRANSACTIONID = $_SESSION['TRANSACTIONID'];
				include_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
				include_once DOL_DOCUMENT_ROOT.'/stripe/class/stripe.class.php';	// This also set $stripearrayofkeysbyenv
				if ($TRANSACTIONID)	// Not linked to a stripe customer, we make the link
				{
					global $stripearrayofkeysbyenv;
					\Stripe\Stripe::setApiKey($stripearrayofkeysbyenv[0]['secret_key']);

					if (preg_match('/^pi_/', $TRANSACTIONID)) {
						// This may throw an error if not found.
						$data = \Stripe\PaymentIntent::retrieve($TRANSACTIONID);    // payment_intent (pi_...)
					}
				 }

				$order = new Commande($this->db);
				$order->fetch($data['metadata']->dol_id);

				if ( $data['amount'] == $order->total_ttc * 100) {
					$invoice = new Facture($this->db);
					$result = $invoice->createFromOrder($order, $user);
					if ( $result > 0 ) {
						$order->classifyBilled($user);
						$invoice->validate($user);

						include_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
						$paiement = new Paiement($this->db);
						$paiement->datepaye = dol_now();
						$paiement->amounts = array($invoice->id => $order->total_ttc);
						if (empty($paymentTypeId))
						{
							$paymentType = $_SESSION["paymentType"];
							if (empty($paymentType)) $paymentType = 'CB';
							$paymentTypeId = dol_getIdFromCode($this->db, $paymentType, 'c_paiement', 'code', 'id', 1);
						}
						$paiement->paiementid = $paymentTypeId;
						$paiement->ext_payment_id = $TRANSACTIONID;

						$paiement->create($user, 1);

						if (!empty($conf->banque->enabled))
						{
							$bankaccountid = $conf->global->STRIPE_BANK_ACCOUNT_FOR_PAYMENTS;
							$label = '(CustomerInvoicePayment)';
							$paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, '', '');
						}
						$this->db->commit();
					}
				}

				break;

			default:
				dol_syslog("Trigger '".$this->name."' for action '$action' launched by ".__FILE__.". id=".$object->id);
				break;
		}

		return 0;
	}
}