import crypto from 'crypto'

export class CallbackClient {
  constructor({ url, bearerToken, hmacSecret }) {
    this.url = url
    this.bearerToken = bearerToken
    this.hmacSecret = hmacSecret || ''
  }

  async postStatus(payload) {
    const body = JSON.stringify(payload)
    const headers = {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${this.bearerToken}`
    }

    if (this.hmacSecret) {
      headers['X-MAKE-Signature'] = crypto
        .createHmac('sha256', this.hmacSecret)
        .update(body)
        .digest('hex')
    }

    const res = await fetch(this.url, {
      method: 'POST',
      headers,
      body
    })

    if (!res.ok) {
      const text = await res.text()
      throw new Error(`WHMCS callback failed (${res.status}): ${text}`)
    }
  }
}
