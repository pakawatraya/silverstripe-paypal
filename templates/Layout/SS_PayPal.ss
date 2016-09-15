<div class="container formatted-text">
  <% if $Status == failed %>
    <p>
      <strong>$StatusCode</strong><br><br>
      $Message<br>
      <a class="btn" href="$PaymentLink" title="bezahlen mit PayPal">Zahlvorgang erneut starten</a>
    </p>
  <% else_if $Status == success %>
    <p>
      $Message<br>
      <a class="btn" href="$BaseUrl" title="zur Startseite">Zurück zur Startseite</a>
    </p>
  <% end_if %>
</div>