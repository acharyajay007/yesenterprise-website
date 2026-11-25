<h1>New {{ $submission->type }} #{{ $submission->id }}</h1>
<p>Name: {{ $submission->name }}</p>
<p>Email: {{ $submission->email }}</p>
<p>Phone: {{ $submission->phone }}</p>
<p>Company: {{ $submission->company }}</p>
<p>Product: {{ $submission->product }} / {{ $submission->variant }}</p>
<p>Quantity: {{ $submission->quantity }}</p>
<p>Role: {{ $submission->role }}</p>
<p>{{ $submission->message }}</p>
<p><a href="{{ url('/admin/submissions') }}">View in the authenticated admin panel</a>.</p>
<p>Resumes are retained privately and are not attached to email.</p>
