"""The Jev client the command line uses, configured from the environment the PHP SDK reads."""

from __future__ import annotations

import os

import httpx2
from typesafe_sdk import TypeSafeClient, TypeSafeError

SYSTEM_ONE_PATHS = {'typesafe': '/v1/systemone', 'openrouter': '/api/alpha/decisions'}


def client_from_env() -> TypeSafeClient:
    """
    A client configured as the PHP SDK reads the environment: `TYPESAFE_PROVIDER=openrouter` sends
    calls to OpenRouter's decisions endpoint keyed by `OPENROUTER_API_KEY`. The bodies are the same
    on both providers and the SDK knows one path, so OpenRouter is a base URL and a path rewrite in
    the transport.

    :raises TypeSafeError: when the provider's API key is missing
    """
    provider = os.environ.get('TYPESAFE_PROVIDER', '').strip().lower() or 'typesafe'

    if provider != 'openrouter':
        return TypeSafeClient()

    key = os.environ.get('OPENROUTER_API_KEY', '').strip()
    if key == '':
        raise TypeSafeError('No API key was provided. Set the OPENROUTER_API_KEY environment variable.')

    return TypeSafeClient(
        api_key=key,
        base_url=os.environ.get('TYPESAFE_BASE_URL', '').strip() or 'https://openrouter.ai',
        transport=_Rewriting(httpx2.HTTPTransport(), SYSTEM_ONE_PATHS['openrouter']),
    )


class _Rewriting(httpx2.BaseTransport):
    def __init__(self, inner: httpx2.BaseTransport, path: str) -> None:
        self._inner = inner
        self._path = path

    def handle_request(self, request: httpx2.Request) -> httpx2.Response:
        if request.url.path == SYSTEM_ONE_PATHS['typesafe']:
            request.url = request.url.copy_with(path=self._path)

        return self._inner.handle_request(request)

    def close(self) -> None:
        self._inner.close()
