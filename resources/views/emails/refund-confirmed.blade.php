<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>Refund Confirmation</title>
</head>
<body>
	<h1>{{ config('app.name') }} Refund Processed</h1>
	<p>Hello {{ $userName }},</p>
	<p>Your refund for order #{{ $orderId }} has been processed.</p>
	<table>
		<tr><td>Refund amount</td><td>{{ $currency }} {{ $refundAmount }}</td></tr>
		<tr><td>Transaction</td><td>{{ $txnId }}</td></tr>
		<tr><td>Date processed</td><td>{{ $date }}</td></tr>
		<tr><td>Payment method</td><td>{{ $method }}</td></tr>
	</table>
	@if (count($items) > 0)
		<h2>Tickets</h2>
		<table>
			@foreach ($items as $item)
				<tr>
					<td>{{ $item->name }} x {{ $item->quantity }}</td>
					<td>{{ $currency }} {{ number_format($item->price, 2) }}</td>
				</tr>
			@endforeach
		</table>
	@endif
</body>
</html>
