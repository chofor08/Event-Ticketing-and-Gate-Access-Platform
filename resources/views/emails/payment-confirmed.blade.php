<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>Payment Confirmation</title>
</head>
<body>
	<h1>{{ config('app.name') }} Payment Received</h1>
	<p>Hello {{ $userName }},</p>
	<p>Your payment for order #{{ $orderId }} is confirmed.</p>
	<table>
		<tr><td>Amount paid</td><td>{{ $amount }} {{ $currency }}</td></tr>
		<tr><td>Payment reference</td><td>{{ $txnId }}</td></tr>
		<tr><td>Date</td><td>{{ $date }}</td></tr>
		<tr><td>Payment method</td><td>{{ $method }}</td></tr>
	</table>
	@if (count($items) > 0)
		<h2>Tickets</h2>
		<table>
			@foreach ($items as $item)
				<tr>
					<td>{{ $item->name }} x {{ $item->quantity }}</td>
					<td>{{ number_format($item->price, 0) }} {{ $currency }}</td>
				</tr>
			@endforeach
		</table>
	@endif
</body>
</html>
