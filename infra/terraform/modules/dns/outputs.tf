output "hosted_zone_id" {
  value = local.hosted_zone_id
}

output "name_servers" {
  value = var.create_hosted_zone ? aws_route53_zone.this[0].name_servers : []
}
