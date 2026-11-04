import { describe, it, expect } from 'vitest'
import { portList, shortImage, shortPort } from '../pages/admin/Containers'

describe('container list formatting', () => {
  it('shortens digests but keeps tags', () => {
    expect(shortImage('sha256:39d0d31dde6630c47174defcd91366a44138026e88d586e7203036901568c440')).toBe('sha256:39d0d31dde66')
    expect(shortImage('dxflrs/garage@sha256:4c9b34c113e61358466e83fd6e7d66e6d18657ede14b776eb78a93ee8da7cf6a')).toBe('dxflrs/garage@4c9b34c113e6')
    expect(shortImage('mariadb:11')).toBe('mariadb:11')
    expect(shortImage('worksuite-documentserver:10.1.9')).toBe('worksuite-documentserver:10.1.9')
  })
  it('shortens published ports and drops IPv4/IPv6 duplicates', () => {
    expect(shortPort('0.0.0.0:18081->18081/tcp')).toBe('18081→18081')
    expect(shortPort('127.0.0.1:8080->80/tcp')).toBe('local 8080→80')
    expect(shortPort('3000/tcp')).toBe('3000')
    expect(shortPort('53/udp')).toBe('53/udp')
    expect(portList(['0.0.0.0:443->443/tcp', '[::]:443->443/tcp', '0.0.0.0:80->80/tcp'])).toEqual(['443→443', '80→80'])
    expect(portList('3306/tcp')).toEqual(['3306'])
    expect(portList(undefined)).toEqual([])
  })
})
