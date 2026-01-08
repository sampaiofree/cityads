@push('scripts')
    <script>
        (function () {
            const tokenUrl = @json(route('meta.sdk-token'));
            const scopes = @json(config('meta.oauth_scopes'));
            const graphVersion = @json(config('meta.graph_version', 'v20.0'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            let sdkPromise = null;
            let currentAppId = null;

            function ensureSdk(appId) {
                if (sdkPromise && currentAppId === appId && window.FB) {
                    return sdkPromise;
                }

                currentAppId = appId;
                sdkPromise = new Promise((resolve) => {
                    window.fbAsyncInit = function () {
                        if (!window.FB) {
                            resolve();
                            return;
                        }

                        window.FB.init({
                            appId: appId,
                            cookie: true,
                            xfbml: false,
                            version: graphVersion,
                        });

                        resolve();
                    };

                    if (window.FB) {
                        window.fbAsyncInit();
                        return;
                    }

                    if (!document.getElementById('facebook-jssdk')) {
                        const script = document.createElement('script');
                        script.id = 'facebook-jssdk';
                        script.src = 'https://connect.facebook.net/pt_BR/sdk.js';
                        document.body.appendChild(script);
                    }
                });

                return sdkPromise;
            }

            function saveToken(accessToken, expiresIn) {
                fetch(tokenUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken || '',
                    },
                    body: JSON.stringify({
                        accessToken: accessToken,
                        expiresIn: expiresIn,
                    }),
                })
                    .then((response) => {
                        if (!response.ok) {
                            return response.json().then((data) => {
                                throw data;
                            });
                        }
                        return response.json();
                    })
                    .then(() => window.location.reload())
                    .catch(() => {
                        alert('Falha ao salvar o token. Tente novamente.');
                    });
            }

            function loginWithSdk(appId) {
                if (!appId) {
                    alert('App ID nao configurado.');
                    return;
                }

                ensureSdk(appId).then(() => {
                    if (!window.FB) {
                        alert('SDK do Facebook nao carregou.');
                        return;
                    }

                    window.FB.login(function (response) {
                        if (response.authResponse) {
                            saveToken(response.authResponse.accessToken, response.authResponse.expiresIn);
                        } else {
                            alert('Login cancelado.');
                        }
                    }, {
                        scope: scopes,
                    });
                });
            }

            window.addEventListener('meta-sdk-connect', (event) => {
                loginWithSdk(event?.detail?.appId);
            });
        })();
    </script>
@endpush
