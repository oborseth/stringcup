// e2ee.js
//
// Signal-like ratchet layer for Stringcup
// - Identity: X25519 keypair, stored in localStorage
// - Session: per-peer shared secret from X25519(identity_priv, identity_pub_remote)
// - Ratchet: symmetric sending/receiving chain keys + per-message keys
// - Crypto: HKDF-SHA256 (WebCrypto) + AES-256-GCM (WebCrypto)
//
// NOTE: This is "production style" code, but NOT security-audited.
//       Do not use for ultra-high-risk communications without review.
//

import { x25519 } from '/js/noble/curves/esm/ed25519.js';
//import { x25519 } from '/js/noble/curves/ed25519.js';
//import { x25519 } from 'https://esm.sh/@noble/curves@1.6.0/ed25519';

const enc = new TextEncoder();
const dec = new TextDecoder();

// --- Configuration ---
const DEFAULT_API_BASE    = 'https://stringcup.com/api/v1';
const ID_STORAGE_KEY      = 'stringcup_e2ee_identity_v3';
const SESSION_STORAGE_KEY = 'stringcup_e2ee_sessions_v1';

// ============================================================================
// Helpers: base64 <-> bytes
// ============================================================================
export function bytesToBase64(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
  return btoa(s);
}

export function base64ToBytes(b64) {
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return bytes;
}

// ============================================================================
// Identity management (localStorage)
// ============================================================================

export function saveIdentity(identity) {
  localStorage.setItem(ID_STORAGE_KEY, JSON.stringify(identity));
}

export function loadIdentity() {
  const raw = localStorage.getItem(ID_STORAGE_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function clearIdentity() {
  localStorage.removeItem(ID_STORAGE_KEY);
  localStorage.removeItem(SESSION_STORAGE_KEY);
}

/**
 * generateIdentity()
 * - Generates an X25519 identity keypair in the browser
 * - Saves it to localStorage
 *
 * @param {string} externalId
 * @param {string|null} displayName
 * @returns {{ externalId, displayName, privKeyB64, pubKeyB64, apiToken?: string }}
 */
export function generateIdentity(externalId, displayName = null) {
  if (!externalId) throw new Error('externalId is required');

  const privBytes = x25519.utils.randomPrivateKey(); // 32 bytes
  const pubBytes  = x25519.getPublicKey(privBytes);  // 32 bytes

  const identity = {
    externalId,
    displayName,
    privKeyB64: bytesToBase64(privBytes),
    pubKeyB64:  bytesToBase64(pubBytes),
    // apiToken will be added after first successful uploadIdentity()
  };

  // NOTE: changing identity invalidates prior sessions
  saveIdentity(identity);
  localStorage.removeItem(SESSION_STORAGE_KEY);

  return identity;
}

// ============================================================================
// Auth headers helper (per-identity token)
// ============================================================================

function authHeaders(includeJson = true) {
  const headers = {};
  if (includeJson) {
    headers['Content-Type'] = 'application/json';
  }

  const identity = loadIdentity();
  if (identity && identity.apiToken) {
    headers['Authorization'] = `Bearer ${identity.apiToken}`;
  }

  return headers;
}

/**
 * uploadIdentity()
 * - Registers/updates the current identity with the backend
 * - On first creation, reads api_token from server and stores it in identity
 *
 * @param {string} [apiBase]
 * @returns {Promise<object>}
 */
export async function uploadIdentity(apiBase = DEFAULT_API_BASE) {
  const identity = loadIdentity();
  if (!identity) throw new Error('No identity in localStorage. Call generateIdentity() first.');

  const body = {
    external_id:         identity.externalId,
    display_name:        identity.displayName,
    identity_public_key: identity.pubKeyB64,
    algo:                'x25519',
  };

  const res  = await fetch(`${apiBase}/identities`, {
    method: 'POST',
    headers: authHeaders(true), // include JSON + Authorization if available
    body: JSON.stringify(body),
  });

  const json = await res.json();
  if (!res.ok) {
    throw new Error(`uploadIdentity failed: ${res.status} ${JSON.stringify(json)}`);
  }

  // If this was first registration, backend returns api_token once
  if (json.api_token) {
    identity.apiToken = json.api_token;
    saveIdentity(identity);
  }

  return json;
}

// ============================================================================
// Session storage (per peer) in localStorage
// ============================================================================

function loadAllSessions() {
  const raw = localStorage.getItem(SESSION_STORAGE_KEY);
  if (!raw) return {};
  try {
    return JSON.parse(raw);
  } catch {
    return {};
  }
}

function saveAllSessions(sessions) {
  localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(sessions));
}

/**
 * Returns or creates a ratchet session for (me <-> peerId).
 *
 * Session shape:
 * {
 *   myId: string,
 *   peerId: string,
 *   rootKeyB64: string,
 *   sendChainKeyB64: string,
 *   recvChainKeyB64: string,
 *   sendSeq: number,
 *   recvSeq: number
 * }
 */
async function getOrCreateSession(peerId, apiBase = DEFAULT_API_BASE) {
  const identity = loadIdentity();
  if (!identity) throw new Error('No identity in localStorage.');

  const myId = identity.externalId;
  const sessions = loadAllSessions();
  const key = `${myId}::${peerId}`;
  let sess = sessions[key];

  if (sess) return sess;

  // --- Create new session ---
  // Shared secret = X25519(identity_priv, identity_pub_remote)
  const myPriv = base64ToBytes(identity.privKeyB64);

  // NOTE: we assume /identities/:id is public or protected separately.
  const resRecip = await fetch(`${apiBase}/identities/${encodeURIComponent(peerId)}`, {
    headers: authHeaders(false), // no need for Content-Type on GET
  });
  const recipJson = await resRecip.json();
  if (!resRecip.ok) {
    throw new Error(`Peer not found: ${resRecip.status} ${JSON.stringify(recipJson)}`);
  }
  const recipPub = base64ToBytes(recipJson.identity_public_key);
  if (recipPub.length !== 32) {
    throw new Error(`Peer public key must be 32 bytes, got ${recipPub.length}`);
  }

  const sharedSecret = x25519.getSharedSecret(myPriv, recipPub); // 32 bytes

  // Root key derivation: HKDF(sharedSecret, "stringcup-root", sortedIds)
  const idsCanonical = [myId, peerId].sort().join('<->');
  const rootKey = await hkdf(sharedSecret, 'stringcup-root', idsCanonical, 32);

  // Derive directional chain keys
  // sendCK = HKDF(rootKey, "stringcup-ck", `${myId}->${peerId}`)
  // recvCK = HKDF(rootKey, "stringcup-ck", `${peerId}->${myId}`)
  const sendCK = await hkdf(rootKey, 'stringcup-ck', `${myId}->${peerId}`, 32);
  const recvCK = await hkdf(rootKey, 'stringcup-ck', `${peerId}->${myId}`, 32);

  sess = {
    myId,
    peerId,
    rootKeyB64:      bytesToBase64(rootKey),
    sendChainKeyB64: bytesToBase64(sendCK),
    recvChainKeyB64: bytesToBase64(recvCK),
    sendSeq: 0,
    recvSeq: 0,
  };

  sessions[key] = sess;
  saveAllSessions(sessions);
  return sess;
}

function updateSession(sess) {
  const sessions = loadAllSessions();
  const key = `${sess.myId}::${sess.peerId}`;
  sessions[key] = sess;
  saveAllSessions(sessions);
}

// ============================================================================
// HKDF helpers using WebCrypto deriveBits
// ============================================================================

async function hkdf(ikmBytes, saltStr, infoStr, length) {
  const salt = enc.encode(saltStr);
  const info = enc.encode(infoStr);

  const keyMaterial = await crypto.subtle.importKey(
    'raw',
    ikmBytes,
    { name: 'HKDF' },
    false,
    ['deriveBits']
  );

  const bits = await crypto.subtle.deriveBits(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt,
      info,
    },
    keyMaterial,
    length * 8
  );

  return new Uint8Array(bits);
}

/**
 * Symmetric chain KDF:
 *  - Input: chainKey (32 bytes)
 *  - Output: { ckNext: 32 bytes, msgKey: 32 bytes }
 */
async function kdfChain(chainKeyBytes, contextStr) {
  // Derive 64 bytes; split into [nextCK | msgKey]
  const out = await hkdf(
    chainKeyBytes,
    'stringcup-chain',
    contextStr,
    64
  );
  const ckNext = out.slice(0, 32);
  const mk     = out.slice(32, 64);
  return { ckNext, msgKey: mk };
}

// ============================================================================
// AES-GCM using message keys (32-byte random key)
// ============================================================================

async function aesEncrypt(msgKeyBytes, plaintextBytes) {
  const key = await crypto.subtle.importKey(
    'raw',
    msgKeyBytes,
    { name: 'AES-GCM' },
    false,
    ['encrypt']
  );

  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ciphertext = new Uint8Array(
    await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintextBytes)
  );
  return { iv, ciphertext };
}

async function aesDecrypt(msgKeyBytes, ivBytes, ciphertextBytes) {
  const key = await crypto.subtle.importKey(
    'raw',
    msgKeyBytes,
    { name: 'AES-GCM' },
    false,
    ['decrypt']
  );

  const plaintext = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: ivBytes },
    key,
    ciphertextBytes
  );
  return new Uint8Array(plaintext);
}

// ============================================================================
// encryptMessage()  (ratcheted, but NO network)
// ============================================================================

/**
 * encryptMessage()
 * - Uses / creates a symmetric ratchet session with peerId
 * - Advances send chain, derives a per-message key, encrypts plaintext
 * - Returns { header, ciphertextB64, ivB64 }
 *
 * @param {string} peerId  - recipient external_id
 * @param {string} plaintext
 * @param {string} [apiBase]
 */
export async function encryptMessage(peerId, plaintext, apiBase = DEFAULT_API_BASE) {
  const identity = loadIdentity();
  if (!identity) throw new Error('No identity in localStorage. Call generateIdentity().');

  if (!peerId) throw new Error('peerId is required.');
  if (!plaintext) throw new Error('plaintext is required.');

  const sess = await getOrCreateSession(peerId, apiBase);

  // Load chain key
  let chainKey = base64ToBytes(sess.sendChainKeyB64);
  const seq    = sess.sendSeq;

  // KDF to get next chain key + message key
  const { ckNext, msgKey } = await kdfChain(chainKey, `${sess.myId}->${peerId}#${seq}`);
  const plaintextBytes = enc.encode(plaintext);
  const { iv, ciphertext } = await aesEncrypt(msgKey, plaintextBytes);

  // Update session state
  sess.sendChainKeyB64 = bytesToBase64(ckNext);
  sess.sendSeq         = seq + 1;
  updateSession(sess);

  const header = {
    version: 1,
    algo:    'x25519+sym-ratchet+aes-gcm',
    msg_seq: seq,
  };

  return {
    header,
    ciphertextB64: bytesToBase64(ciphertext),
    ivB64:         bytesToBase64(iv),
  };
}

// ============================================================================
// decryptMessage() (ratcheted, NO network)
// ============================================================================

/**
 * decryptMessage()
 * - Uses the ratchet session for (senderId -> me)
 * - Advances receive chain up to msg_seq to derive correct message key
 *
 * @param {string} senderId
 * @param {object} storedMessage  - message from API ({ header, ciphertext, ... })
 * @param {string} [apiBase]
 * @returns {Promise<string>} plaintext
 */
export async function decryptMessage(senderId, storedMessage, apiBase = DEFAULT_API_BASE) {
  const identity = loadIdentity();
  if (!identity) throw new Error('No identity in localStorage.');

  const sess = await getOrCreateSession(senderId, apiBase);

  const expectedAlgo = 'x25519+sym-ratchet+aes-gcm';
  if (!storedMessage.header || storedMessage.header.algo !== expectedAlgo) {
    throw new Error(`Unsupported algo: ${storedMessage.header?.algo}`);
  }

  const msgSeq = storedMessage.header.msg_seq;
  if (typeof msgSeq !== 'number') {
    throw new Error('Message header missing numeric msg_seq');
  }

  let chainKey = base64ToBytes(sess.recvChainKeyB64);
  let seq      = sess.recvSeq;

  if (msgSeq < seq) {
    // We moved past this sequence already; no skipped-key storage yet
    throw new Error(`Message seq ${msgSeq} is older than current recvSeq ${seq}`);
  }

  let msgKey = null;
  // Step the chain until we reach msgSeq
  while (seq <= msgSeq) {
    const res = await kdfChain(chainKey, `${senderId}->${sess.myId}#${seq}`);
    chainKey = res.ckNext;
    if (seq === msgSeq) {
      msgKey = res.msgKey;
    }
    seq++;
  }

  if (!msgKey) {
    throw new Error('Failed to derive message key');
  }

  const ivBytes         = base64ToBytes(storedMessage.header.iv || storedMessage.ivB64 || '');
  const ciphertextBytes = base64ToBytes(storedMessage.ciphertext);

  const plaintextBytes = await aesDecrypt(msgKey, ivBytes, ciphertextBytes);
  const plaintext      = dec.decode(plaintextBytes);

  // Update session recv state
  sess.recvChainKeyB64 = bytesToBase64(chainKey);
  sess.recvSeq         = seq;
  updateSession(sess);

  return plaintext;
}

// ============================================================================
// sendMessage()  (network + encryptMessage)
// ============================================================================

/**
 * sendMessage()
 * - High-level convenience:
 *   1) encryptMessage(peerId, plaintext)
 *   2) POST to /messages
 *
 * @param {string} recipientId
 * @param {string} plaintext
 * @param {string} [apiBase]
 * @returns {Promise<object>} server JSON
 */
export async function sendMessage(recipientId, plaintext, apiBase = DEFAULT_API_BASE) {
  const identity = loadIdentity();
  if (!identity) throw new Error('No identity in localStorage.');

  const { header, ciphertextB64, ivB64 } = await encryptMessage(recipientId, plaintext, apiBase);

  const body = {
    sender_id:    identity.externalId, // server should still enforce via token
    recipient_id: recipientId,
    header: {
      ...header,
      iv: ivB64,
    },
    ciphertext: ciphertextB64,
  };

  const res  = await fetch(`${apiBase}/messages`, {
    method: 'POST',
    headers: authHeaders(true),
    body: JSON.stringify(body),
  });

  const json = await res.json();
  if (!res.ok) {
    throw new Error(`sendMessage failed: ${res.status} ${JSON.stringify(json)}`);
  }
  return json;
}

// ============================================================================
// fetchMessages()  (network + optional decrypt)
// ============================================================================

/**
 * fetchMessages()
 * - GETs /messages for the current identity (based on token)
 * - If decrypt=true, runs decryptMessage() on each
 *
 * @param {boolean} [decrypt=false]
 * @param {string}  [apiBase]
 * @returns {Promise<Array<{ raw: object, plaintext?: string, error?: string }>>}
 */
export async function fetchMessages(decrypt = false, apiBase = DEFAULT_API_BASE) {
  const identity = loadIdentity();
  if (!identity) throw new Error('No identity in localStorage.');

  // Backend should derive recipient from token; no ?recipient_id anymore
  const res  = await fetch(`${apiBase}/messages`, {
    method: 'GET',
    headers: authHeaders(false),
  });

  const msgs = await res.json();
  if (!res.ok) {
    throw new Error(`fetchMessages failed: ${res.status} ${JSON.stringify(msgs)}`);
  }

  if (!decrypt) {
    return msgs.map(m => ({ raw: m }));
  }

  const out = [];
  for (const m of msgs) {
    try {
      const plaintext = await decryptMessage(m.sender_id, m, apiBase);
      out.push({ raw: m, plaintext });
    } catch (e) {
      out.push({ raw: m, error: e.message });
    }
  }
  return out;
}

// ============================================================================
// Inbox watcher (polling) – convenience for auto fetch
// ============================================================================

let inboxWatcherTimer = null;

/**
 * startInboxWatcher()
 * - Periodically calls fetchMessages()
 * - If new messages arrive, calls onBatch(messages)
 *
 * @param {Object} options
 * @param {boolean} [options.decrypt=true]    - whether to auto-decrypt
 * @param {function} [options.onBatch]        - callback(messagesArray)
 * @param {number} [options.intervalMs=3000]  - polling interval in ms
 */
export function startInboxWatcher({
  decrypt = true,
  onBatch,
  intervalMs = 3000,
} = {}) {
  if (inboxWatcherTimer !== null) {
    // Already running; no-op
    return;
  }

  inboxWatcherTimer = setInterval(async () => {
    try {
      // If no identity, nothing to do
      const identity = loadIdentity();
      if (!identity) return;

      const msgs = await fetchMessages(decrypt);
      if (msgs && msgs.length > 0 && typeof onBatch === 'function') {
        onBatch(msgs);
      }
    } catch (e) {
      // Library-level: just log to console; UI can handle user-visible logs
      console.error('Inbox watcher error:', e);
    }
  }, intervalMs);
}

/**
 * stopInboxWatcher()
 * - Stops the polling loop.
 */
export function stopInboxWatcher() {
  if (inboxWatcherTimer !== null) {
    clearInterval(inboxWatcherTimer);
    inboxWatcherTimer = null;
  }
}

