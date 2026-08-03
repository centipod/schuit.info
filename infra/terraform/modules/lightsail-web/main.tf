resource "aws_lightsail_static_ip" "this" {
  name = var.static_ip_name
}

resource "aws_lightsail_instance" "this" {
  name              = var.instance_name
  availability_zone = var.availability_zone
  blueprint_id      = var.blueprint_id
  bundle_id         = var.bundle_id
  key_pair_name     = var.key_pair_name
  user_data = templatefile("${path.module}/user-data.sh.tftpl", {
    aws_region         = var.aws_region
    backup_bucket_name = var.backup_bucket_name
    domain_name        = var.domain_name
    environment        = var.environment
    project_name       = var.project_name
  })

  add_on {
    type          = "AutoSnapshot"
    snapshot_time = var.auto_snapshot_time
    status        = "Enabled"
  }

  tags = var.tags

  lifecycle {
    # Lightsail user_data only ever runs once, at first boot - it is not
    # re-executed on update, so there is nothing for Terraform to "apply" by
    # replacing the instance. Without this, editing the bootstrap script
    # (packages, nginx config, anything) makes Terraform destroy+recreate a
    # live server, wiping any state that only exists on its local disk
    # (MySQL data directory, webtrees media, WordPress uploads) unless it
    # has already been backed up elsewhere. Ongoing config/app changes must
    # go through a separate deploy path (SSH/rsync), not user_data.
    ignore_changes = [user_data]
  }
}

resource "aws_lightsail_static_ip_attachment" "this" {
  static_ip_name = aws_lightsail_static_ip.this.name
  instance_name  = aws_lightsail_instance.this.name
}

resource "aws_lightsail_instance_public_ports" "this" {
  instance_name = aws_lightsail_instance.this.name

  port_info {
    protocol  = "tcp"
    from_port = 22
    to_port   = 22
    cidrs     = var.ssh_cidrs
  }

  port_info {
    protocol  = "tcp"
    from_port = 80
    to_port   = 80
    cidrs     = ["0.0.0.0/0"]
  }

  port_info {
    protocol  = "tcp"
    from_port = 443
    to_port   = 443
    cidrs     = ["0.0.0.0/0"]
  }
}
