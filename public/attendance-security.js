(() => {
  const root = document.querySelector('[data-attendance-verification]');
  if (!root) return;
  const button = root.querySelector('[data-verify]'), status = root.querySelector('[data-verification-status]');
  if (!button) return;
  const purpose = root.dataset.purpose;
  const encode = buffer => { let s = ''; for (const b of new Uint8Array(buffer)) s += String.fromCharCode(b); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); };
  const decode = s => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
  const post = async (url, body) => {
    const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(body)});
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Verification failed. Please start again.');
    return data;
  };
  const report = reason => post('/attendance/verification-failure', {reason, purpose}).catch(() => {});
  const locate = () => new Promise((resolve, reject) => {
    if (!navigator.geolocation) { report('location_unavailable'); return reject(new Error('Location is unavailable in this browser.')); }
    navigator.geolocation.getCurrentPosition(position => resolve({latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy, timestamp: position.timestamp}), error => {
      const reasons = {1: ['location_denied', 'Location permission was denied. Enable precise location for this website and retry.'], 2: ['location_unavailable', 'Your current location is unavailable. Move outdoors and retry.'], 3: ['location_timeout', 'Location request timed out. Retry with a stronger GPS signal.']};
      const [reason, message] = reasons[error.code] || reasons[2]; report(reason); reject(new Error(message));
    }, {enableHighAccuracy: true, maximumAge: 0, timeout: 20000});
  });
  button.addEventListener('click', async () => {
    button.disabled = true;
    try {
      if (!window.isSecureContext || !window.PublicKeyCredential || !navigator.credentials) { report('device_unsupported'); throw new Error('WebAuthn is unavailable. Open the configured HTTPS address on a compatible device.'); }
      if (!(await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable())) { report('device_unsupported'); throw new Error('This device has no supported platform authenticator. Set up a device PIN or biometric unlock, or use a compatible device.'); }
      const registering = purpose === 'register';
      const label = root.querySelector('#attendance-device-label')?.value.trim();
      if (registering && !label) throw new Error('Enter a device label.');
      status.textContent = registering ? 'Preparing secure device registration…' : 'Obtaining your current location…';
      const body = registering ? {password: root.querySelector('#attendance-password').value} : {location: await locate()};
      const result = await post(`/attendance/verification/${purpose}/options`, body);
      if (registering) root.querySelector('#attendance-password').value = '';
      const options = result.options.publicKey;
      options.challenge = decode(options.challenge);
      if (registering) options.user.id = decode(options.user.id);
      for (const key of ['allowCredentials', 'excludeCredentials']) if (options[key]) options[key] = options[key].map(c => ({...c, id: decode(c.id)}));
      status.textContent = 'Verify with your device’s fingerprint, face recognition or PIN…';
      const credential = await navigator.credentials[registering ? 'create' : 'get']({publicKey: options});
      if (!credential || (registering && credential.authenticatorAttachment !== 'platform')) throw new Error('A platform credential on this device is required.');
      const response = credential.response;
      const payload = {challenge_id: result.challenge_id, id: encode(credential.rawId), clientDataJSON: encode(response.clientDataJSON), label};
      if (registering) payload.attestationObject = encode(response.attestationObject);
      else {
        payload.authenticatorData = encode(response.authenticatorData); payload.signature = encode(response.signature); payload.userHandle = response.userHandle ? encode(response.userHandle) : null;
        status.textContent = 'Rechecking your current location…'; payload.location = await locate();
      }
      const confirmed = await post(`/attendance/verification/${purpose}/verify`, payload);
      status.textContent = confirmed.message;
      const link = document.createElement('a'); link.href = '/attendance'; link.textContent = ' View my attendance'; status.append(link);
    } catch (error) {
      if (['NotAllowedError', 'AbortError', 'InvalidStateError'].includes(error.name)) { report('device_cancelled'); status.textContent = 'Device verification was cancelled, unavailable or timed out. Use your registered device and start again.'; }
      else status.textContent = error.message || 'Verification failed. Start again.';
      button.disabled = false;
    }
  });
})();
