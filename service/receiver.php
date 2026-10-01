<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\service;

use minos\moderation\client_loader;
use Minos\Client\Signature;
use Minos\Client\WebhookPayload;

/**
 * The webhook's logic, in the order of the contract's receiving checklist:
 *
 * 0. with the extension switched off, `404`: it does nothing, and the gateway retries until
 *    its TTL while the held posts wait in phpBB's queue for a moderator;
 * 1. the raw body, as it arrived;
 * 2. the signature (`Signature::verify` of the bundled client): `401` when it fails, so the
 *    gateway retries. Without a usable stored secret every delivery is refused: an HMAC keyed
 *    with an empty string is one anybody can compute;
 * 3. the payload (`WebhookPayload::parse`): `400` when it is not one; an id this forum does not
 *    wait for (unknown, of an earlier revision, or already handled) gets `200` and nothing
 *    else, because deliveries repeat;
 * 4. the outcome is recorded, and applied after the answer is sent.
 */
class receiver
{
	/** The signature header (a wire label, never renamed). */
	const SIGNATURE_HEADER = 'X-Wergiliusz-Podpis';

	/** @var settings */
	protected $settings;

	/** @var pending_store */
	protected $store;

	/**
	 * @param settings      $settings The administrator's settings (the webhook secret).
	 * @param pending_store $store    The pending rows.
	 */
	public function __construct(settings $settings, pending_store $store)
	{
		client_loader::register();
		$this->settings = $settings;
		$this->store = $store;
	}

	/**
	 * Handles one delivery.
	 *
	 * @param string $body      The exact bytes of the request body.
	 * @param string $signature The `X-Wergiliusz-Podpis` header.
	 * @param int    $now       Unix seconds.
	 * @return array{0:int,1:int|null} The HTTP status to answer, and the post whose recorded
	 *     outcome is to be applied after the answer (null when there is none).
	 */
	public function receive($body, $signature, $now)
	{
		if (!$this->settings->enabled())
		{
			return array(404, null);
		}
		$secret = $this->settings->webhook_secret();
		if (!settings::valid_secret($secret) || !Signature::verify($secret, (string) $signature, (string) $body, (int) $now))
		{
			return array(401, null);
		}

		$payload = WebhookPayload::parse((string) $body);
		if ($payload === null)
		{
			return array(400, null);
		}

		$ref = submitter::parse_item_id($payload['id']);
		if ($ref === null)
		{
			return array(200, null);
		}
		list($post_id, $revision) = $ref;

		// A row of another revision, or one that is not waiting, fails the conditional update.
		$verdict = ($payload['status'] === WebhookPayload::ASSESSED) ? (string) $payload['kwalifikacja'] : WebhookPayload::UNASSESSED;
		$recorded = $this->store->record_verdict($post_id, $revision, $verdict, $payload['kategorie'],
			$payload['wsparcie'], $payload['ocenzurowany']);

		return array(200, $recorded ? $post_id : null);
	}
}
