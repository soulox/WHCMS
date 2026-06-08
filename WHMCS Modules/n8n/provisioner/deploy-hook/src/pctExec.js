import { spawn } from 'child_process'

export class PctExec {
  constructor({ pctBin }) {
    this.pctBin = pctBin
  }

  async exec(vmid, command) {
    return this.run([this.pctBin, 'exec', String(vmid), '--', 'bash', '-lc', command])
  }

  async push(vmid, targetPath, content) {
    const b64 = Buffer.from(String(content), 'utf8').toString('base64')
    const cmd = `mkdir -p \"$(dirname '${escapeSingle(targetPath)}')\" && base64 -d > '${escapeSingle(targetPath)}' <<'EOF'\n${b64}\nEOF`
    return this.exec(vmid, cmd)
  }

  run(args) {
    return new Promise((resolve, reject) => {
      const [bin, ...rest] = args
      const child = spawn(bin, rest, { stdio: ['ignore', 'pipe', 'pipe'] })
      let stdout = ''
      let stderr = ''

      child.stdout.on('data', (d) => {
        stdout += d.toString()
      })
      child.stderr.on('data', (d) => {
        stderr += d.toString()
      })
      child.on('error', reject)
      child.on('close', (code) => {
        if (code === 0) {
          resolve({ stdout: stdout.trim(), stderr: stderr.trim() })
          return
        }

        reject(new Error(`Command failed (${code}): ${bin} ${rest.join(' ')}\n${stderr || stdout}`))
      })
    })
  }
}

function escapeSingle(input) {
  return String(input).replace(/'/g, "'\\''")
}
