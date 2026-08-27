<?php
// app/services/emails/order_delivery_note_email.php
// A packing-slip style confirmation of what was handed over — deliberately
// no pricing/payment framing (that's the invoice's job); this just confirms
// the goods and quantities for the customer's own records.
//
// Usage:
//   $msg = build_order_delivery_note_email($order, $items, ['name'=>..]);
//   $mailer->send($order['customer_email'], $msg['subject'], $msg['html'], $msg['text']);

function build_order_delivery_note_email(array $order, array $items, array $shop): array
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    $shopName = $shop['name'] ?? 'the shop';
    $meta = '';
    foreach ([
        'Location' => $order['customer_location'] ?? '',
        'Delivery person' => $order['delivery_person'] ?? '',
    ] as $label => $value) {
        if (trim((string) $value) !== '') {
            $meta .= '<p style="margin:2px 0;color:#64748b;font-size:13px;">' . $h($label) . ': <strong>' . $h($value) . '</strong></p>';
        }
    }
    if ((float) ($order['delivery_fee'] ?? 0) > 0) {
        $meta .= '<p style="margin:2px 0;color:#64748b;font-size:13px;">Delivery fee: <strong>KES ' . number_format((float) $order['delivery_fee'], 2) . '</strong></p>';
    }

    $rows = '';
    foreach ($items as $it) {
        $qty = rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.');
        $rows .= '<tr>'
            . '<td style="padding:8px 4px;border-bottom:1px solid #f1f5f9;">' . $h($it['product_name']) . '</td>'
            . '<td style="padding:8px 4px;border-bottom:1px solid #f1f5f9;text-align:right;">' . $h($qty) . '</td>'
            . '</tr>';
    }

    $subject = 'Delivery note ' . $order['receipt_number'] . ' from ' . $shopName;

    $html = <<<HTML
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f1f5f9;padding:32px 0">
  <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">
    <div style="padding:24px 28px;border-bottom:1px solid #f1f5f9">
      <p style="margin:0;font-size:1.05rem;font-weight:700;color:#0f172a">{$h($shopName)}</p>
      <p style="margin:4px 0 0;font-size:.85rem;color:#64748b">Delivery note {$h($order['receipt_number'])}</p>
    </div>
    <div style="padding:28px">
      <h1 style="margin:0 0 12px;font-size:20px;color:#0f172a">Hi {$h($order['table_name'])},</h1>
      <p style="margin:0 0 18px;color:#475569;font-size:15px;line-height:1.5">
        This confirms the following products were handed over to you on {$h(date('j M Y', strtotime($order['created_at'])))}, for your records.
      </p>
      {$meta}
      <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;margin-bottom:8px">
        <tr style="color:#64748b;font-size:12px;text-transform:uppercase;">
          <td style="padding:4px;">Item</td><td style="padding:4px;text-align:right;">Qty delivered</td>
        </tr>
        {$rows}
      </table>
      <p style="margin:20px 0 0;color:#94a3b8;font-size:13px;line-height:1.5">
        Any questions about this delivery? Just reply to this email.
      </p>
    </div>
  </div>
</div>
HTML;

    $textLines = ["Delivery note {$order['receipt_number']} from {$shopName}", ''];
    foreach ($items as $it) {
        $textLines[] = $it['product_name'] . ' — qty ' . rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.');
    }
    if (!empty($order['customer_location'])) { $textLines[] = 'Location: ' . $order['customer_location']; }
    if (!empty($order['delivery_person'])) { $textLines[] = 'Delivery person: ' . $order['delivery_person']; }
    if ((float) ($order['delivery_fee'] ?? 0) > 0) { $textLines[] = 'Delivery fee: KES ' . number_format((float) $order['delivery_fee'], 2); }
    $text = implode("\n", $textLines);

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}
